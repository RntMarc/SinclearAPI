<?php

declare(strict_types=1);

namespace Sinclear\Api\Services\Games;

use InvalidArgumentException;

/**
 * Registry aller verfuegbaren Spiele-Integrationen (game-Slug -> Integration).
 */
final readonly class GameRegistry
{
    /** @var array<string, GameStatsIntegrationInterface> */
    private array $integrations;

    /**
     * @param array<string, GameStatsIntegrationInterface> $integrations
     */
    public function __construct(array $integrations)
    {
        $this->integrations = $integrations;
    }

    public function has(string $game): bool
    {
        return isset($this->integrations[$game]);
    }

    public function get(string $game): GameStatsIntegrationInterface
    {
        if (!$this->has($game)) {
            throw new InvalidArgumentException('unknown_game');
        }

        return $this->integrations[$game];
    }

    /** @return list<string> */
    public function slugs(): array
    {
        return array_keys($this->integrations);
    }
}
