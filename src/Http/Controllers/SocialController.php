<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\CloseFriendRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

final class SocialController
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly CloseFriendRepository $closeFriendRepository
    ) {
    }

    public function visibleUnsplashHandles(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }

        // Get close friend IDs for visibility=2 check
        $incoming = $this->closeFriendRepository->findIncoming($user->id);
        $closeFriendIds = array_map(
            static fn (array $r): string => (string) ($r['userId'] ?? ''),
            $incoming
        );

        // Build query with OR conditions
        $conditions = ['si.unsplashVisibility = 1'];
        $params = ['currentUserId' => $user->id];

        if ($closeFriendIds !== []) {
            $placeholders = [];
            foreach ($closeFriendIds as $i => $fid) {
                $key = 'cf_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $fid;
            }
            $conditions[] = '(si.unsplashVisibility = 2 AND si.userId IN (' . implode(',', $placeholders) . '))';
        }

        $conditions[] = 'si.userId = :currentUserId';
        $where = implode(' OR ', $conditions);

        $stmt = $this->pdo->prepare(
            "SELECT si.unsplashHandle, si.userId, u.displayName
             FROM SocialInfo si
             INNER JOIN `User` u ON u.id = si.userId
             WHERE ($where)"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return ResponseFactory::json(['data' => $rows], 200, $response);
    }
}
