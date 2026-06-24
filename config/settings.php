<?php

$settings = [];

$settings['app'] = [
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? 'https://api.example.com',
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

$settings['fcm'] = [
    'project_id' => $_ENV['FCM_PROJECT_ID'] ?? '',
    'client_email' => $_ENV['FCM_CLIENT_EMAIL'] ?? '',
    'private_key' => $_ENV['FCM_PRIVATE_KEY'] ?? '',
];

return $settings;
