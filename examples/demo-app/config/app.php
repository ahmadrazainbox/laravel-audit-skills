<?php

return [
    'name' => env('APP_NAME', 'Demo'),

    // FLAW: debug defaults to true. If APP_DEBUG is unset in production the
    // error page renders the stack trace and every environment variable.
    'debug' => env('APP_DEBUG', true),

    'url' => env('APP_URL', 'http://localhost'),
    'key' => env('APP_KEY'),
];
