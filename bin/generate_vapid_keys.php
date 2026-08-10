#!/usr/bin/env php
<?php
/**
 * Generates a VAPID key pair for Web Push notifications.
 *
 * Usage: php bin/generate_vapid_keys.php
 *
 * The keys are output to stdout. Copy them into your .env file.
 */

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keyPair = VAPID::createVapidKeys();

echo "Add these to your .env file:\n\n";
echo "VAPID_PUBLIC_KEY=" . $keyPair['publicKey'] . "\n";
echo "VAPID_PRIVATE_KEY=" . $keyPair['privateKey'] . "\n";
echo "VAPID_SUBJECT=mailto:contact@sinclear.de\n";
