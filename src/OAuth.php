<?php

require_once __DIR__ . '/Config.php';

/**
 * Handles the OAuth install flow with Zid.  When the merchant clicks
 * `/install`, they are redirected to Zid to authorise the app.  Zid
 * then sends them back to `/oauth/callback` with an auth code, which
 * is exchanged for an access token and stored on disk.
 */
class OAuth
{
    /**
     * Redirect the merchant to Zid’s authorization page.
     */
    public static function redirectToZid(): void
    {
        $clientId    = Config::get('ZID_CLIENT_ID');
        $redirectUri = rtrim(Config::get('APP_BASE_URL'), '/') . '/oauth/callback';
        // Request read access to products and orders; adjust scopes as needed.
        $scopes      = urlencode('products.read orders.read');
        $authorizeUrl = rtrim(Config::get('ZID_OAUTH_URL'), '/') . '/oauth/authorize';

        $url = sprintf(
            '%s?client_id=%s&redirect_uri=%s&response_type=code&scope=%s',
            $authorizeUrl,
            urlencode($clientId),
            urlencode($redirectUri),
            $scopes
        );
        header('Location: ' . $url);
        exit;
    }

    /**
     * Handle the OAuth callback by exchanging the `code` for an access token.
     */
    public static function handleCallback(): void
    {
        $code = $_GET['code'] ?? null;
        if (!$code) {
            http_response_code(400);
            echo 'Missing authorization code';
            return;
        }

        $tokenUrl     = rtrim(Config::get('ZID_OAUTH_URL'), '/') . '/oauth/token';
        $clientId     = Config::get('ZID_CLIENT_ID');
        $clientSecret = Config::get('ZID_CLIENT_SECRET');
        $redirectUri  = rtrim(Config::get('APP_BASE_URL'), '/') . '/oauth/callback';

        $data = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            http_response_code(500);
            echo 'cURL error: ' . htmlspecialchars($error);
            return;
        }

        $json = json_decode($response, true);
        if (!is_array($json) || !isset($json['access_token'])) {
            http_response_code(500);
            echo 'Failed to obtain access token';
            return;
        }

        // Persist tokens (including refresh_token and expiry if provided)
        $storagePath = __DIR__ . '/../storage/tokens.json';
        file_put_contents($storagePath, json_encode($json, JSON_PRETTY_PRINT));

        echo 'App installed successfully. You may close this window.';
    }
}