<?php

// Published to config/kissflow.php by: php artisan vendor:publish --tag=kissflow-config

return [
    'base_url' => env('KISSFLOW_BASE_URL'),
    'account_id' => env('KISSFLOW_ACCOUNT_ID'),
    'access_key_id' => env('KISSFLOW_ACCESS_KEY_ID'),
    'access_key_secret' => env('KISSFLOW_ACCESS_KEY_SECRET'),

    'verify_ssl' => env('KISSFLOW_VERIFY_SSL', true),
    'timeout' => env('KISSFLOW_TIMEOUT', 60),
    'max_retries' => env('KISSFLOW_MAX_RETRIES', 2),
    'retry_delay_ms' => env('KISSFLOW_RETRY_DELAY_MS', 500),
    'page_delay_ms' => env('KISSFLOW_PAGE_DELAY_MS', 100),
    'force_http11' => env('KISSFLOW_FORCE_HTTP11', true),

    // Log channel for request/error logs; null = default channel.
    'log_channel' => env('KISSFLOW_LOG_CHANNEL'),
];
