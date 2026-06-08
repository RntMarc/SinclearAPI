<?php

declare(strict_types=1);

namespace Sinclear\Api\Application;

/**
 * Typed access to application configuration.
 */
final class Settings
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config
    ) {
    }

    /**
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = $this->config;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('app.debug', false);
    }

    public function getAppUrl(): string
    {
        return (string) $this->get('app.url', 'http://localhost');
    }
}
