<?php

/**
 * Simple configuration helper that reads environment variables.  All
 * configuration values are retrieved from getenv() to keep secrets out of
 * your code.  Provide sensible defaults where appropriate.
 */
class Config
{
    public static function get(string $key, $default = null)
    {
        // Build a map of expected keys to environment values.  You can
        // extend this map if you add more configuration options.
        $map = [
            'ZID_CLIENT_ID'     => getenv('ZID_CLIENT_ID'),
            'ZID_CLIENT_SECRET' => getenv('ZID_CLIENT_SECRET'),
            'APP_BASE_URL'      => getenv('APP_BASE_URL'),
            'ZID_OAUTH_URL'     => getenv('ZID_OAUTH_URL') ?: 'https://oauth.zid.sa',
            'ZID_API_URL'       => getenv('ZID_API_URL') ?: 'https://api.zid.sa',
        ];
        return $map[$key] ?? $default;
    }
}