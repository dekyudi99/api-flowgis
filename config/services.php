<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */
    'flask' => [
        'url' => env('FLASK_ENGINE_URL', 'http://localhost:5001'),
    ],

    'geoserver' => [
        'wms_url' => env('GEOSERVER_WMS_URL', 'http://localhost:8080/geoserver/wms'),
    ],

    'astragis' => [
        'base_url'     => str_replace(':8001', ':8000', env('ASTRAGIS_BASE_URL', 'http://fastapi_backend:8000')),
        'api_key'      => env('ASTRAGIS_API_KEY', 'agis_sk_flowgis_production_key_2026'),
        'workspace_id' => env('ASTRAGIS_WORKSPACE_ID', '7'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cctv' => [
        'username' => env('CCTV_USERNAME', 'live'),
        'password' => env('CCTV_PASSWORD', 'Live2025!'),
        'stations' => [
            'TA130204' => env('CCTV_URL_STATION_1', 'http://ta130204.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
            'TA130205' => env('CCTV_URL_STATION_2', 'http://ta130205.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
            'TA130206' => env('CCTV_URL_STATION_3', 'http://ta130206.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
            'TA100220' => env('CCTV_URL_STATION_4', 'http://ta100220.dyndns.info:5001/axis-cgi/mjpg/video.cgi'),
        ],
    ],

];
