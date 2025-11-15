<?php

require_once __DIR__ . '/Config.php';

/**
 * Minimal wrapper around the Zid REST API.  Handles authentication by
 * reading the access token from storage and sends authenticated
 * requests to the API endpoints.  This class can be extended to
 * support more API calls as needed.
 */
class ZidApi
{
    /**
     * Retrieve the stored access token from disk.
     */
    private static function getToken(): ?string
    {
        $file = __DIR__ . '/../storage/tokens.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return $data['access_token'] ?? null;
    }

    /**
     * Make an HTTP request to the Zid API.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path   API path starting with `/`
     * @param array  $params Query or body parameters
     */
    private static function request(string $method, string $path, array $params = [])
    {
        $token = self::getToken();
        if (!$token) {
            return null;
        }

        $base = rtrim(Config::get('ZID_API_URL'), '/');
        $url  = $base . $path;
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        // For GET requests append params to query string
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        // For other methods encode params as JSON
        if ($method !== 'GET' && !empty($params)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($params);
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            return null;
        }
        return json_decode($resp, true);
    }

    /**
     * Fetch products.  Pagination is supported via `page` and
     * `page_size` parameters.
     */
    public static function getProducts(int $page = 1, int $pageSize = 50)
    {
        return self::request('GET', '/v1/products/', [
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * Fetch orders.  Pagination is supported via `page` and
     * `page_size` parameters.
     */
    public static function getOrders(int $page = 1, int $pageSize = 50)
    {
        return self::request('GET', '/v1/managers/store/orders', [
            'page'      => $page,
            'page_size' => $pageSize,
        ]);
    }
}