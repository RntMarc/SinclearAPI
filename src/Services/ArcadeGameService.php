<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\ArcadeGameRepository;
use Sinclear\Api\Repository\ArcadeScoreRepository;
use Sinclear\Api\Repository\ArcadeSessionRepository;

final readonly class ArcadeGameService
{
    public function __construct(
        private ArcadeGameRepository $repo,
        private ArcadeSessionRepository $sessionRepo,
        private ArcadeScoreRepository $scoreRepo,
        private Settings $settings,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        return array_map($this->format(...), $this->repo->listActive());
    }

    /** @return list<array<string, mixed>> */
    public function listAllForAdmin(): array
    {
        return array_map($this->format(...), $this->repo->listAll());
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        return $this->repo->findActiveBySlug($slug);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->repo->findBySlug($slug);
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->repo->findById($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $data = $this->normalize($input, false);

        if ($this->repo->slugExists($data['slug'])) {
            throw new InvalidArgumentException('slug_taken');
        }

        $id = $this->repo->create($data);
        $game = $this->repo->findById($id);

        if ($game === null) {
            throw new \RuntimeException('arcade_game_persist_failed');
        }

        return $this->format($game);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input): array
    {
        $game = $this->repo->findById($id);
        if ($game === null) {
            throw new InvalidArgumentException('game_not_found');
        }

        $data = $this->normalize($input, true);
        if ($data === []) {
            throw new InvalidArgumentException('no_fields_to_update');
        }

        if (isset($data['slug']) && $this->repo->slugExists($data['slug'], $id)) {
            throw new InvalidArgumentException('slug_taken');
        }

        $this->repo->update($id, $data);
        $updated = $this->repo->findById($id);

        if ($updated === null) {
            throw new \RuntimeException('arcade_game_persist_failed');
        }

        return $this->format($updated);
    }

    public function delete(string $id): bool
    {
        $game = $this->repo->findById($id);
        if ($game === null) {
            return false;
        }

        // Keine FKs: abhaengige Sessions und Scores explizit mit entfernen.
        $this->sessionRepo->deleteByGameId($id);
        $this->scoreRepo->deleteByGameId($id);

        return $this->repo->delete($id);
    }

    public function buildPlayUrl(string $slug, ?string $token = null): string
    {
        $base = rtrim((string) ($this->settings->app['url'] ?? ''), '/');
        $url = $base . '/api/arcade/' . rawurlencode($slug) . '/index.html';

        if ($token !== null && $token !== '') {
            $url .= '#arcade_token=' . rawurlencode($token);
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $game
     * @return array<string, mixed>
     */
    public function format(array $game): array
    {
        return [
            'id' => $game['id'],
            'slug' => $game['slug'],
            'title' => $game['title'],
            'description' => $game['description'] ?? null,
            'thumbnailUrl' => $game['thumbnailUrl'] ?? null,
            'scoreDirection' => $game['scoreDirection'],
            'isActive' => (bool) $game['isActive'],
            'sortOrder' => (int) $game['sortOrder'],
            'playUrl' => $this->buildPlayUrl((string) $game['slug']),
            'createdAt' => self::formatTimestamp($game['createdAt'] ?? null),
            'updatedAt' => self::formatTimestamp($game['updatedAt'] ?? null),
        ];
    }

    public static function isValidSlug(string $slug): bool
    {
        return strlen($slug) <= 50
            && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,48}[a-z0-9])?$/D', $slug) === 1;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input, bool $partial): array
    {
        $data = [];

        if (!$partial || array_key_exists('slug', $input)) {
            $slugInput = $input['slug'] ?? '';
            $slug = is_string($slugInput) ? trim($slugInput) : '';
            if ($slug === '') {
                throw new InvalidArgumentException('slug_required');
            }
            if (!self::isValidSlug($slug)) {
                throw new InvalidArgumentException('invalid_slug');
            }
            $data['slug'] = $slug;
        }

        if (!$partial || array_key_exists('title', $input)) {
            $titleInput = $input['title'] ?? '';
            $title = is_string($titleInput) ? trim($titleInput) : '';
            if ($title === '') {
                throw new InvalidArgumentException('title_required');
            }
            if (mb_strlen($title) > 191) {
                throw new InvalidArgumentException('title_too_long');
            }
            $data['title'] = $title;
        }

        if (!$partial || array_key_exists('description', $input)) {
            $description = $input['description'] ?? null;
            $data['description'] = is_string($description) && trim($description) !== ''
                ? trim($description)
                : null;
        }

        if (!$partial || array_key_exists('thumbnailUrl', $input)) {
            $thumbnailUrl = $input['thumbnailUrl'] ?? null;
            if (is_string($thumbnailUrl) && trim($thumbnailUrl) !== '') {
                $thumbnailUrl = trim($thumbnailUrl);
                if (mb_strlen($thumbnailUrl) > 1024) {
                    throw new InvalidArgumentException('thumbnail_url_too_long');
                }
                $data['thumbnailUrl'] = $thumbnailUrl;
            } else {
                $data['thumbnailUrl'] = null;
            }
        }

        if (!$partial || array_key_exists('scoreDirection', $input)) {
            $direction = $input['scoreDirection'] ?? 'desc';
            if (!is_string($direction) || !in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException('invalid_score_direction');
            }
            $data['scoreDirection'] = $direction;
        }

        if (!$partial || array_key_exists('isActive', $input)) {
            $data['isActive'] = self::parseBool($input['isActive'] ?? true);
        }

        if (!$partial || array_key_exists('sortOrder', $input)) {
            $sortOrder = $input['sortOrder'] ?? 0;
            if (is_string($sortOrder) && preg_match('/^-?\d+$/D', $sortOrder) === 1) {
                $sortOrder = (int) $sortOrder;
            }
            if (!is_int($sortOrder)) {
                throw new InvalidArgumentException('invalid_sort_order');
            }
            $data['sortOrder'] = $sortOrder;
        }

        return $data;
    }

    private static function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => throw new InvalidArgumentException('invalid_is_active'),
            };
        }

        throw new InvalidArgumentException('invalid_is_active');
    }

    private static function formatTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
