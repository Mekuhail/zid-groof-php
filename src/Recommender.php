<?php

require_once __DIR__ . '/ZidApi.php';

/**
 * Implements very simple recommendation logic using historical order data.
 *
 * The recommender builds a co‑occurrence matrix over products purchased together.
 * When asked for recommendations for a single product or a cart, it
 * returns products that co‑occur frequently, falling back to same‑category
 * or random products if no co‑occurrence data exist.
 */
class Recommender
{
    /** @var array<string, mixed> List of products keyed by ID */
    private static $products = [];
    /** @var array<int, array<string, mixed>> List of orders */
    private static $orders = [];
    /** @var array<int, array<int,int>> Co‑occurrence matrix [pid][pid2] => count */
    private static $cooccurrence = [];
    /** Flag to ensure data is loaded only once */
    private static $loaded = false;

    /**
     * Ensure products and orders are loaded from cache or API.  Data is
     * cached to storage/cache.json to avoid hitting the API repeatedly.
     */
    private static function loadData(): void
    {
        if (self::$loaded) {
            return;
        }
        $cacheFile = __DIR__ . '/../storage/cache.json';
        $data = null;
        if (file_exists($cacheFile)) {
            $contents = file_get_contents($cacheFile);
            if ($contents) {
                $data = json_decode($contents, true);
            }
        }
        // If cache exists, use it
        if (is_array($data) && isset($data['products'], $data['orders'])) {
            foreach ($data['products'] as $prod) {
                if (isset($prod['id'])) {
                    self::$products[$prod['id']] = $prod;
                }
            }
            self::$orders = $data['orders'];
        } else {
            // Fetch from API
            $productsResp = ZidApi::getProducts(1, 100);
            $ordersResp   = ZidApi::getOrders(1, 100);
            if (isset($productsResp['data']) && is_array($productsResp['data'])) {
                foreach ($productsResp['data'] as $prod) {
                    if (isset($prod['id'])) {
                        self::$products[$prod['id']] = $prod;
                    }
                }
            }
            if (isset($ordersResp['data']) && is_array($ordersResp['data'])) {
                // Some API responses nest orders under 'orders'; adjust accordingly
                $orders = $ordersResp['data'];
                // Flatten orders; each order should have 'items' list
                self::$orders = $orders;
            }
            // Persist to cache if we successfully loaded products
            if (!empty(self::$products)) {
                file_put_contents($cacheFile, json_encode([
                    'products' => array_values(self::$products),
                    'orders'   => self::$orders,
                ], JSON_PRETTY_PRINT));
            }
        }
        self::$loaded = true;
        // Build co‑occurrence matrix
        self::$cooccurrence = self::buildCooccurrence(self::$orders);
    }

    /**
     * Build a co‑occurrence matrix from orders.
     * Each pair of product IDs purchased in the same order increments the count.
     * @param array $orders
     * @return array
     */
    private static function buildCooccurrence(array $orders): array
    {
        $matrix = [];
        foreach ($orders as $order) {
            // Extract product IDs from the order.  Zid API may return
            // items under `items` or `order_items` keys; each item
            // should have `product_id`.  Try both.
            $items = [];
            if (isset($order['items']) && is_array($order['items'])) {
                $items = $order['items'];
            } elseif (isset($order['order_items']) && is_array($order['order_items'])) {
                $items = $order['order_items'];
            }
            $productIds = [];
            foreach ($items as $item) {
                $pid = $item['product_id'] ?? $item['productId'] ?? null;
                if ($pid) {
                    $productIds[] = (int)$pid;
                }
            }
            // Remove duplicates within the same order
            $productIds = array_unique($productIds);
            // Increment co‑occurrence counts for each pair
            $count = count($productIds);
            for ($i = 0; $i < $count; $i++) {
                $pid1 = $productIds[$i];
                for ($j = $i + 1; $j < $count; $j++) {
                    $pid2 = $productIds[$j];
                    if (!isset($matrix[$pid1][$pid2])) {
                        $matrix[$pid1][$pid2] = 0;
                        $matrix[$pid2][$pid1] = 0;
                    }
                    $matrix[$pid1][$pid2]++;
                    $matrix[$pid2][$pid1]++;
                }
            }
        }
        return $matrix;
    }

    /**
     * Get product recommendations for a single product.
     * Responds with JSON encoded array of products.
     */
    public static function productRecommendations(): void
    {
        self::loadData();
        $productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
        header('Content-Type: application/json');
        if (!$productId || !isset(self::$products[$productId])) {
            echo json_encode([]);
            return;
        }
        $recommendations = [];
        // Use co‑occurrence counts if available
        if (isset(self::$cooccurrence[$productId])) {
            $scores = self::$cooccurrence[$productId];
            // Sort descending by frequency
            arsort($scores);
            foreach ($scores as $pid => $count) {
                if ($pid == $productId) {
                    continue;
                }
                if (isset(self::$products[$pid])) {
                    $recommendations[] = self::formatProduct(self::$products[$pid]);
                }
                if (count($recommendations) >= 5) {
                    break;
                }
            }
        }
        // If not enough, fallback to same category or random
        if (count($recommendations) < 5) {
            $fallback = self::fallbackProducts($productId, 5 - count($recommendations));
            $recommendations = array_merge($recommendations, $fallback);
        }
        echo json_encode($recommendations);
    }

    /**
     * Get recommendations based on products in a cart.  Expects a JSON
     * body with `product_ids` array.  Responds with JSON encoded array
     * of products.
     */
    public static function cartRecommendations(): void
    {
        self::loadData();
        $input = json_decode(file_get_contents('php://input'), true);
        $cartIds = [];
        if (is_array($input) && isset($input['product_ids']) && is_array($input['product_ids'])) {
            $cartIds = array_map('intval', $input['product_ids']);
        }
        header('Content-Type: application/json');
        if (empty($cartIds)) {
            echo json_encode([]);
            return;
        }
        $scores = [];
        // Aggregate co‑occurrence scores across all items in cart
        foreach ($cartIds as $pid) {
            if (isset(self::$cooccurrence[$pid])) {
                foreach (self::$cooccurrence[$pid] as $otherPid => $count) {
                    if (in_array($otherPid, $cartIds, true) || !isset(self::$products[$otherPid])) {
                        continue;
                    }
                    $scores[$otherPid] = ($scores[$otherPid] ?? 0) + $count;
                }
            }
        }
        // Sort by aggregated score
        arsort($scores);
        $recommendations = [];
        foreach ($scores as $pid => $score) {
            if (!isset(self::$products[$pid])) {
                continue;
            }
            $recommendations[] = self::formatProduct(self::$products[$pid]);
            if (count($recommendations) >= 5) {
                break;
            }
        }
        // Fallback if fewer than 5
        if (count($recommendations) < 5) {
            // Use first product in cart as reference for category fallback
            $first = $cartIds[0];
            $fallback = self::fallbackProducts($first, 5 - count($recommendations), $cartIds);
            $recommendations = array_merge($recommendations, $fallback);
        }
        echo json_encode($recommendations);
    }

    /**
     * Select fallback products based on the same category as the given product
     * ID.  If no category data is available, random products are returned.  You
     * can exclude certain product IDs to avoid recommending items already
     * present.
     *
     * @param int   $productId  Product to base fallback on
     * @param int   $limit      Maximum number of products to return
     * @param array $excludeIds IDs to exclude from results
     * @return array List of formatted product arrays
     */
    private static function fallbackProducts(int $productId, int $limit, array $excludeIds = []): array
    {
        $results = [];
        if (!isset(self::$products[$productId])) {
            return $results;
        }
        $product = self::$products[$productId];
        // Determine categories; Zid API may list categories under `category_id`,
        // `category` or `categories`.  Normalize to an array of IDs.
        $categories = [];
        if (isset($product['category_id'])) {
            $categories[] = (int)$product['category_id'];
        }
        if (isset($product['categories']) && is_array($product['categories'])) {
            foreach ($product['categories'] as $cat) {
                if (is_array($cat) && isset($cat['id'])) {
                    $categories[] = (int)$cat['id'];
                } elseif (is_int($cat)) {
                    $categories[] = $cat;
                }
            }
        }
        $categories = array_unique($categories);
        // Filter products by same category
        $candidates = [];
        if (!empty($categories)) {
            foreach (self::$products as $pid => $p) {
                if ($pid == $productId || in_array($pid, $excludeIds, true)) {
                    continue;
                }
                // Check if product shares at least one category
                $pCats = [];
                if (isset($p['category_id'])) {
                    $pCats[] = (int)$p['category_id'];
                }
                if (isset($p['categories']) && is_array($p['categories'])) {
                    foreach ($p['categories'] as $cat) {
                        if (is_array($cat) && isset($cat['id'])) {
                            $pCats[] = (int)$cat['id'];
                        } elseif (is_int($cat)) {
                            $pCats[] = $cat;
                        }
                    }
                }
                if (array_intersect($categories, $pCats)) {
                    $candidates[$pid] = self::formatProduct($p);
                }
            }
        }
        // If no category data, fall back to all products
        if (empty($candidates)) {
            foreach (self::$products as $pid => $p) {
                if ($pid == $productId || in_array($pid, $excludeIds, true)) {
                    continue;
                }
                $candidates[$pid] = self::formatProduct($p);
            }
        }
        // Shuffle candidates and return up to $limit
        shuffle($candidates);
        return array_slice(array_values($candidates), 0, $limit);
    }

    /**
     * Format a product into a simple array with only the fields needed by
     * the frontend.  Adjust the keys depending on your store’s product
     * structure.  A default image and price of 0 are provided if fields
     * are missing.
     *
     * @param array $product Raw product data from Zid API
     * @return array
     */
    private static function formatProduct(array $product): array
    {
        $image = '';
        // Zid API may return images under `main_image`, `image`, or nested
        if (isset($product['main_image'])) {
            $image = $product['main_image'];
        } elseif (isset($product['image'])) {
            $image = $product['image'];
        } elseif (isset($product['images']) && is_array($product['images']) && count($product['images']) > 0) {
            $image = $product['images'][0];
        }
        $price = $product['price'] ?? ($product['price_after_discount'] ?? 0);
        return [
            'id'    => $product['id'] ?? null,
            'title' => $product['title'] ?? ($product['name'] ?? ''),
            'image' => $image,
            'price' => $price,
        ];
    }
}