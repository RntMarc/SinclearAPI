<?php

$settings = [];

$settings['app'] = [
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim($_ENV['APP_URL'] ?? 'https://api.example.com', '/'),
];

$settings['db'] = [
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '3306',
    'name' => $_ENV['DB_NAME'] ?? '',
    'user' => $_ENV['DB_USER'] ?? '',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
];

$settings['jwt'] = [
    'private_key' => $_ENV['JWT_PRIVATE_KEY'] ?? '',
    'public_key' => $_ENV['JWT_PUBLIC_KEY'] ?? '',
    'issuer' => $_ENV['JWT_ISSUER'] ?? 'sinclear-api',
    'access_ttl' => (int)($_ENV['JWT_ACCESS_TTL'] ?? 900),
    'refresh_ttl' => (int)($_ENV['JWT_REFRESH_TTL'] ?? 7776000),
];

$settings['discord'] = [
    'client_id' => $_ENV['DISCORD_CLIENT_ID'] ?? '',
    'client_secret' => $_ENV['DISCORD_CLIENT_SECRET'] ?? '',
    'redirect_uri' => $_ENV['DISCORD_REDIRECT_URI'] ?? '',
    'relink_redirect_uri' => $_ENV['DISCORD_RELINK_REDIRECT_URI'] ?? $_ENV['DISCORD_REDIRECT_URI'] ?? '',
    'register_redirect_uri' => $_ENV['DISCORD_REGISTER_REDIRECT_URI'] ?? $_ENV['DISCORD_REDIRECT_URI'] ?? '',
    'guild_id' => $_ENV['DISCORD_GUILD_ID'] ?? '',
];

$settings['smtp'] = [
    'host' => $_ENV['SMTP_HOST'] ?? '',
    'port' => (int)($_ENV['SMTP_PORT'] ?? 587),
    'user' => $_ENV['SMTP_USER'] ?? '',
    'password' => $_ENV['SMTP_PASSWORD'] ?? '',
    'from' => $_ENV['SMTP_FROM'] ?? 'noreply@sinclear.app',
    'admin_email' => $_ENV['SMTP_ADMIN_EMAIL'] ?? '',
];

$settings['cors'] = [
    'allowed_origins' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? ''),
];

$settings['rate_limit'] = [
    'requests' => (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 100),
    'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
    'auth_requests' => (int)($_ENV['AUTH_RATE_LIMIT_REQUESTS'] ?? 10),
    'auth_window' => (int)($_ENV['AUTH_RATE_LIMIT_WINDOW'] ?? 60),
];

$settings['pagination'] = [
    'default_limit' => 20,
    'max_limit' => 100,
];

$settings['unsplash'] = [
    'base_url' => $_ENV['UNSPLASH_BASE_URL'] ?? 'https://api.unsplash.com',
    'access_key' => $_ENV['UNSPLASH_ACCESS_KEY'] ?? '',
    'per_user_limit' => (int)($_ENV['UNSPLASH_PER_USER_LIMIT'] ?? 30),
    'cache_ttl' => (int)($_ENV['UNSPLASH_CACHE_TTL'] ?? 21600),
];

$settings['centrifugo'] = [
    'api_url' => $_ENV['CENTRIFUGO_API_URL'] ?? '',
    'api_key' => $_ENV['CENTRIFUGO_API_KEY'] ?? '',
    'hmac_secret' => $_ENV['CENTRIFUGO_HMAC_SECRET'] ?? '',
    'token_ttl' => (int)($_ENV['CENTRIFUGO_TOKEN_TTL'] ?? 900),
    'ws_url' => $_ENV['CENTRIFUGO_WS_URL'] ?? '',
    'timeout' => (int)($_ENV['CENTRIFUGO_TIMEOUT'] ?? 2),
    'enabled' => filter_var($_ENV['CENTRIFUGO_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'log_level' => $_ENV['CENTRIFUGO_LOG_LEVEL'] ?? 'error',
    'issuer' => $_ENV['CENTRIFUGO_ISSUER'] ?? 'sinclear-api',
    'audience' => $_ENV['CENTRIFUGO_AUDIENCE'] ?? 'centrifugo',
    'proxy_key' => $_ENV['CENTRIFUGO_PROXY_KEY'] ?? '',
];

$settings['downloads'] = [
    'base_url' => $_ENV['DOWNLOADS_BASE_URL'] ?? 'https://sinclear.de/downloads',
];

$settings['vapid'] = [
    'public_key' => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
    'private_key' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
    'subject' => $_ENV['VAPID_SUBJECT'] ?? 'mailto:contact@sinclear.de',
];

$settings['external_data'] = [
    'infranode_base_url' => $_ENV['INFRANODE_BASE_URL'] ?? 'https://infranode.dev/api/v1',
    'open_meteo_base_url' => $_ENV['OPEN_METEO_BASE_URL'] ?? 'https://api.open-meteo.com/v1',
    'brightsky_base_url' => $_ENV['BRIGHTSKY_BASE_URL'] ?? 'https://api.brightsky.dev',
    'cache_ttl' => [
        'weather' => (int)($_ENV['EXTERNAL_DATA_CACHE_WEATHER'] ?? 600),
        'weather_warnings' => (int)($_ENV['EXTERNAL_DATA_CACHE_WARNINGS'] ?? 300),
        'pollen_uv' => (int)($_ENV['EXTERNAL_DATA_CACHE_POLLEN'] ?? 1800),
        'air_quality' => (int)($_ENV['EXTERNAL_DATA_CACHE_AIR'] ?? 600),
        'traffic' => (int)($_ENV['EXTERNAL_DATA_CACHE_TRAFFIC'] ?? 300),
        'holidays' => (int)($_ENV['EXTERNAL_DATA_CACHE_HOLIDAYS'] ?? 604800),
        'demographics' => (int)($_ENV['EXTERNAL_DATA_CACHE_DEMOGRAPHICS'] ?? 86400),
        'events' => (int)($_ENV['EXTERNAL_DATA_CACHE_EVENTS'] ?? 3600),
        'default' => (int)($_ENV['EXTERNAL_DATA_CACHE_DEFAULT'] ?? 1800),
    ],
];

return $settings;
