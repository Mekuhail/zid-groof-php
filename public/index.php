<?php

// Front controller for the Zid upsell PoC.  This file is run for
// every request via PHP’s built‑in server or any web server
// configured to point document root at the `public` directory.

require_once __DIR__ . '/../src/Router.php';
require_once __DIR__ . '/../src/OAuth.php';
require_once __DIR__ . '/../src/Recommender.php';

$router = new Router();

// Installation and OAuth routes
$router->get('/install', ['OAuth', 'redirectToZid']);
$router->get('/oauth/callback', ['OAuth', 'handleCallback']);

// Recommendation API endpoints
$router->get('/api/recommendations/product', ['Recommender', 'productRecommendations']);
$router->post('/api/recommendations/cart', ['Recommender', 'cartRecommendations']);

// Dispatch the request
$router->dispatch();