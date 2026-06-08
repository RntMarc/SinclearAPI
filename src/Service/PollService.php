<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use PDO;
use Ramsey\Uuid\Uuid;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\GenericRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;

/**
 * Poll business operations beyond basic CRUD.
 */
final class PollService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param list<array{questionId: string, optionId?: string, availability?: string, textValue?: string}> $answers
     */
    public function vote(AuthenticatedUser $user, string $pollId, array $answers): void
    {
        $pollRepo = new GenericRepository($this->pdo, 'Poll', []);
        $poll = $pollRepo->findById($pollId);
        if ($poll === null) {
            throw HttpException::notFound();
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `PollInvite` WHERE `pollId` = :pollId AND `userId` = :userId LIMIT 1'
        );
        $stmt->execute(['pollId' => $pollId, 'userId' => $user->id]);
        $invited = $stmt->fetchColumn() !== false;

        if (!$invited && (string) $poll['creatorId'] !== $user->id && !$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $questionIds = array_unique(array_column($answers, 'questionId'));

        $this->pdo->beginTransaction();
        try {
            if ($questionIds !== []) {
                $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
                $delete = $this->pdo->prepare(
                    "DELETE FROM `PollVote` WHERE `userId` = ? AND `questionId` IN ({$placeholders})"
                );
                $delete->execute(array_merge([$user->id], $questionIds));
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO `PollVote` (`id`, `questionId`, `optionId`, `userId`, `availability`, `textValue`, `createdAt`)
                 VALUES (:id, :questionId, :optionId, :userId, :availability, :textValue, :createdAt)'
            );

            foreach ($answers as $answer) {
                $insert->execute([
                    'id' => Uuid::uuid4()->toString(),
                    'questionId' => $answer['questionId'],
                    'optionId' => $answer['optionId'] ?? null,
                    'userId' => $user->id,
                    'availability' => $answer['availability'] ?? null,
                    'textValue' => $answer['textValue'] ?? null,
                    'createdAt' => date('Y-m-d H:i:s'),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $proposal
     */
    public function counterProposal(AuthenticatedUser $user, string $pollId, array $proposal): array
    {
        $pollRepo = new GenericRepository($this->pdo, 'Poll', []);
        $poll = $pollRepo->findById($pollId);
        if ($poll === null) {
            throw HttpException::notFound();
        }
        if (!(bool) ($poll['allowCounterProposals'] ?? false)) {
            throw HttpException::forbidden('counter_proposals_disabled');
        }

        $optionRepo = new GenericRepository($this->pdo, 'PollOption', []);
        return $optionRepo->create([
            'id' => Uuid::uuid4()->toString(),
            'questionId' => $proposal['questionId'],
            'label' => $proposal['label'] ?? 'Gegenvorschlag',
            'proposedBy' => $user->id,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    public function finalize(AuthenticatedUser $user, string $pollId, string $optionId): array
    {
        $pollRepo = new GenericRepository($this->pdo, 'Poll', []);
        $poll = $pollRepo->findById($pollId);
        if ($poll === null) {
            throw HttpException::notFound();
        }
        if ((string) $poll['creatorId'] !== $user->id && !$user->isAdmin) {
            throw HttpException::forbidden();
        }

        $updated = $pollRepo->update($pollId, [
            'finalizedOptionId' => $optionId,
            'finalizedAt' => date('Y-m-d H:i:s'),
        ]);

        return $updated ?? $poll;
    }
}
