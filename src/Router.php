<?php

/**
 * A very lightweight router.  It maps HTTP methods and paths to PHP
 * callables.  Only basic pattern matching is supported (exact matches
 * without variables).  This is sufficient for simple PoC routing.
 */
class Router
{
    private $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /**
     * Dispatch the current request.  If no route matches, a 404
     * response is sent.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        $handler = $this->routes[$method][$path] ?? null;
        if (is_callable($handler)) {
            // If the handler is defined as [ClassName, methodName],
            // instantiate the class if it isn’t static.  call_user_func
            // handles both static and instance methods.
            if (is_array($handler)) {
                $class = $handler[0];
                $methodName = $handler[1];
                // Instantiate if not static
                if (!method_exists($class, $methodName)) {
                    http_response_code(500);
                    echo 'Method not found';
                    return;
                }
                call_user_func($handler);
            } else {
                call_user_func($handler);
            }
            return;
        }
        http_response_code(404);
        echo 'Not Found';
    }
}