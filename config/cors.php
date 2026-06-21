<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'), // Baca dari .env saat production
        'https://desaslogo.my.id',
        'https://www.desaslogo.my.id',
        'http://localhost:5174',   
        'http://127.0.0.1:5174',   
        'http://localhost:3000'
    ],
    'allowed_origins_patterns' => [
        '#^https://.*\.vercel\.app$#' // Mengizinkan semua subdomain vercel.app
    ],
    'allowed_headers' => ['*'],

];
