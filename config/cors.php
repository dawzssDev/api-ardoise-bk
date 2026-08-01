<?php

/*
|--------------------------------------------------------------------------
| CORS — consumo desde React con tokens Bearer
|--------------------------------------------------------------------------
|
| Este backend NO usa cookies ni credentials. En el frontend:
|
|   fetch(url, {
|     headers: {
|       Accept: 'application/json',
|       Authorization: `Bearer ${token}`,
|     },
|   })
|
| Sin credentials: 'include'.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'Retry-After',
    ],

    'max_age' => 3600,

    // Bearer tokens: no cookies / no credentials
    'supports_credentials' => false,

];
