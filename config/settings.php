<?php

declare(strict_types=1);

return [
    'app' => [
        'env' => $_ENV['APP_ENV'] ?? 'production',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'url' => rtrim($_ENV['APP_URL'] ?? 'http://localhost', '/'),
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
        'name' => $_ENV['DB_NAME'] ?? 'sinclear',
        'user' => $_ENV['DB_USER'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
    'jwt' => [
        'private_key' => $_ENV['JWT_PRIVATE_KEY'] ?? '',
        'public_key' => $_ENV['JWT_PUBLIC_KEY'] ?? '',
        'issuer' => $_ENV['JWT_ISSUER'] ?? 'sinclear-beyond',
        'access_ttl' => (int) ($_ENV['JWT_ACCESS_TTL'] ?? 900),
        'refresh_ttl' => (int) ($_ENV['JWT_REFRESH_TTL'] ?? 7776000),
    ],
    'discord' => [
        'client_id' => $_ENV['DISCORD_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['DISCORD_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $_ENV['DISCORD_REDIRECT_URI'] ?? '',
        'guild_id' => $_ENV['DISCORD_GUILD_ID'] ?? '',
    ],
    'smtp' => [
        'host' => $_ENV['SMTP_HOST'] ?? '',
        'port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
        'user' => $_ENV['SMTP_USER'] ?? '',
        'password' => $_ENV['SMTP_PASSWORD'] ?? '',
        'from' => $_ENV['SMTP_FROM'] ?? 'noreply@sinclear.de',
    ],
    'webauthn' => [
        'rp_id' => $_ENV['WEBAUTHN_RP_ID'] ?? 'localhost',
        'rp_name' => $_ENV['WEBAUTHN_RP_NAME'] ?? 'Sinclear Beyond',
        'origin' => $_ENV['WEBAUTHN_ORIGIN'] ?? 'http://localhost:8080',
    ],
    'internal_api' => [
        'secret' => $_ENV['AUTH_INTERNAL_SECRET'] ?? $_ENV['INTERNAL_API_SECRET'] ?? '',
    ],
    'cors' => [
        'allowed_origins' => array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*'))),
    ],
    'rate_limit' => [
        'requests' => (int) ($_ENV['RATE_LIMIT_REQUESTS'] ?? 100),
        'window' => (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
        'auth_requests' => (int) ($_ENV['AUTH_RATE_LIMIT_REQUESTS'] ?? 10),
        'auth_window' => (int) ($_ENV['AUTH_RATE_LIMIT_WINDOW'] ?? 60),
    ],
    'pagination' => [
        'default_limit' => 25,
        'max_limit' => 100,
    ],
];
