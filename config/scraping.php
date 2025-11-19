<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Scraping Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the scraping proxy provider to use for fetching flight data.
    | Supported: "brightdata"
    |
    */

    'provider' => env('SCRAPING_PROVIDER', 'brightdata'),

    /*
    |--------------------------------------------------------------------------
    | Bright Data Configuration
    |--------------------------------------------------------------------------
    */

    'brightdata' => [
        'proxy_host' => env('BRIGHTDATA_PROXY_HOST', 'brd.superproxy.io'),
        'proxy_port' => env('BRIGHTDATA_PROXY_PORT', 33335),
        'proxy_user' => env('BRIGHTDATA_PROXY_USER'),
        'proxy_pass' => env('BRIGHTDATA_PROXY_PASS'),
        'timeout' => env('BRIGHTDATA_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kayak Configuration
    |--------------------------------------------------------------------------
    */

    'kayak' => [
        'base_url' => 'https://www.kayak.com/flights',
        'default_params' => [
            'ucs' => 'n8pldp',
            'sort' => 'bestflight_a',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'ttl' => env('FLIGHTS_CACHE_TTL', 3600), // 1 hour in seconds
        'prefix' => 'flights_',
    ],
];
