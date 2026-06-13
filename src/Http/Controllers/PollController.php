<?php

declare(strict_types=1);

namespace Sinclear\Api\Http\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\PollService;

/**
 * Poll action endpoints.
 */
final class PollController
{
    public function __construct(
        private readonly PollService $pollService,
        private readonly \PDO $pdo
    ) {
    }

    public function vote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $answers = $body['answers'] ?? [];
        if (!is_array($answers)) {
            throw HttpException::badRequest('invalid_answers');
        }
        $this->pollService->vote($user, $args['id'], $answers);
        return ResponseFactory::json(['success' => true], 200, $response);
    }

    public function counterProposal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $data = $this->pollService->counterProposal($user, $args['id'], $body);
        return ResponseFactory::json(['data' => $data], 201, $response);
    }

    public function finalize(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $optionId = (string) ($body['optionId'] ?? '');
        if ($optionId === '') {
            throw HttpException::badRequest('missing_option_id');
        }
        $data = $this->pollService->finalize($user, $args['id'], $optionId);
        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    /**
     * GET /polls/list
     * List user's polls with questions, options, and invite counts.
     * Query param: archived=0|1 (default 0)
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $includeArchived = ($request->getQueryParams()['archived'] ?? '0') === '1';

        // Get invited poll IDs
        $invStmt = $this->pdo->prepare("SELECT pollId FROM PollInvite WHERE userId = ?");
        $invStmt->execute([$user->id]);
        $invitedPollIds = array_column($invStmt->fetchAll(), 'pollId');

        $now = time();
        $appointmentThreshold = $now - 86400; // 1 day
        $surveyThreshold = $now - 604800; // 7 days

        $visibilityParts = ['p.creatorId = ?'];
        $params = [$user->id];

        if (count($invitedPollIds) > 0) {
            $ph = implode(',', array_fill(0, count($invitedPollIds), '?'));
            $visibilityParts[] = "p.id IN ({$ph})";
            $params = array_merge($params, $invitedPollIds);
        }

        $visibilitySql = implode(' OR ', $visibilityParts);

        // Archive filter
        if (!$includeArchived) {
            $archiveFilter = "AND (
                p.finalizedOptionId IS NULL
                OR (p.type = 'appointment' AND p.finalizedOptionId IS NOT NULL AND p.finalizedOptionId != 'closed'
                    AND EXISTS (SELECT 1 FROM PollOption po WHERE po.id = p.finalizedOptionId AND po.dateValue >= FROM_UNIXTIME(?)))
                OR (p.type = 'appointment' AND p.finalizedOptionId = 'closed' AND p.updatedAt >= FROM_UNIXTIME(?))
                OR (p.type = 'survey' AND p.finalizedOptionId IS NOT NULL AND p.updatedAt >= FROM_UNIXTIME(?))
            )";
            $params[] = $appointmentThreshold;
            $params[] = $appointmentThreshold;
            $params[] = $surveyThreshold;
        } else {
            $archiveFilter = '';
        }

        $sql = "SELECT p.id, p.type, p.title, p.description, p.creatorId, p.finalizedOptionId,
                       p.allowCounterProposals, p.createdAt, p.updatedAt,
                       u.displayName AS creatorName, u.image AS creatorImage
                FROM `Poll` p
                LEFT JOIN `User` u ON u.id = p.creatorId
                WHERE ({$visibilitySql}) {$archiveFilter}
                ORDER BY p.createdAt DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $polls = $stmt->fetchAll();

        if ($polls === []) {
            return ResponseFactory::json(['data' => []], 200, $response);
        }

        // Get all questions for these polls
        $pollIds = array_column($polls, 'id');
        $phQ = implode(',', array_fill(0, count($pollIds), '?'));
        $qStmt = $this->pdo->prepare("SELECT * FROM PollQuestion WHERE pollId IN ({$phQ}) ORDER BY `order`");
        $qStmt->execute($pollIds);
        $allQuestions = $qStmt->fetchAll();

        // Get all options for these questions
        $questionIds = array_column($allQuestions, 'id');
        $allOptions = [];
        if ($questionIds !== []) {
            $phO = implode(',', array_fill(0, count($questionIds), '?'));
            $oStmt = $this->pdo->prepare("SELECT * FROM PollOption WHERE questionId IN ({$phO}) ORDER BY dateValue, `order`");
            $oStmt->execute($questionIds);
            $allOptions = $oStmt->fetchAll();
        }

        // Enrich polls
        $enriched = array_map(function ($poll) use ($allQuestions, $allOptions) {
            $questions = array_values(array_filter($allQuestions, fn($q) => $q['pollId'] === $poll['id']));
            $qIds = array_column($questions, 'id');
            $options = array_values(array_filter($allOptions, fn($o) => in_array($o['questionId'], $qIds)));
            $poll['questions'] = $questions;
            $poll['options'] = $options;
            return $poll;
        }, $polls);

        return ResponseFactory::json(['data' => $enriched], 200, $response);
    }

    /**
     * GET /polls/{id}/detail
     * Full poll detail with invites, questions, options, and votes.
     */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $pollId = $args['id'];

        // Get poll
        $pollStmt = $this->pdo->prepare(
            "SELECT p.*, u.displayName AS creatorName, u.image AS creatorImage
             FROM `Poll` p
             LEFT JOIN `User` u ON u.id = p.creatorId
             WHERE p.id = ? LIMIT 1"
        );
        $pollStmt->execute([$pollId]);
        $poll = $pollStmt->fetch();
        if (!$poll) {
            throw HttpException::notFound();
        }

        // Get invites
        $invStmt = $this->pdo->prepare(
            "SELECT pi.id, pi.userId, pi.isIndispensable, u.displayName, u.image
             FROM PollInvite pi
             LEFT JOIN `User` u ON u.id = pi.userId
             WHERE pi.pollId = ?"
        );
        $invStmt->execute([$pollId]);
        $invites = $invStmt->fetchAll();

        $isInvited = false;
        foreach ($invites as $inv) {
            if ($inv['userId'] === $user->id) {
                $isInvited = true;
                break;
            }
        }
        $isCreator = $poll['creatorId'] === $user->id;

        if (!$isInvited && !$isCreator) {
            throw HttpException::forbidden();
        }

        // Get questions
        $qStmt = $this->pdo->prepare("SELECT * FROM PollQuestion WHERE pollId = ? ORDER BY `order`");
        $qStmt->execute([$pollId]);
        $questions = $qStmt->fetchAll();

        // Get options
        $options = [];
        $questionIds = array_column($questions, 'id');
        if ($questionIds !== []) {
            $ph = implode(',', array_fill(0, count($questionIds), '?'));
            $oStmt = $this->pdo->prepare("SELECT * FROM PollOption WHERE questionId IN ({$ph}) ORDER BY dateValue, `order`");
            $oStmt->execute($questionIds);
            $options = $oStmt->fetchAll();
        }

        // Get votes
        $votes = [];
        if ($questionIds !== []) {
            $ph = implode(',', array_fill(0, count($questionIds), '?'));
            $vStmt = $this->pdo->prepare("SELECT * FROM PollVote WHERE questionId IN ({$ph})");
            $vStmt->execute($questionIds);
            $allVotes = $vStmt->fetchAll();

            // Filter votes for surveys: only creator sees all
            if ($poll['type'] === 'survey' && !$isCreator) {
                $votes = array_values(array_filter($allVotes, fn($v) => $v['userId'] === $user->id));
            } else {
                $votes = $allVotes;
            }
        }

        return ResponseFactory::json([
            'data' => [
                ...$poll,
                'invites' => $invites,
                'questions' => $questions,
                'options' => $options,
                'votes' => $votes,
                'isCreator' => $isCreator,
            ],
        ], 200, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }

    /**
     * PATCH /polls/{id}
     * Update poll with optional questions/options/invites cascade.
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $pollId = $args['id'];
        $body = (array) ($request->getParsedBody() ?? []);

        // Verify ownership
        $pollStmt = $this->pdo->prepare("SELECT creatorId FROM `Poll` WHERE id = ? LIMIT 1");
        $pollStmt->execute([$pollId]);
        $poll = $pollStmt->fetch();
        if (!$poll) {
            throw HttpException::notFound();
        }
        if ($poll['creatorId'] !== $user->id) {
            throw HttpException::forbidden();
        }

        $this->pdo->beginTransaction();
        try {
            // Update poll metadata
            $updateFields = [];
            $params = [];
            if (isset($body['title'])) {
                $updateFields[] = 'title = ?';
                $params[] = $body['title'];
            }
            if (array_key_exists('description', $body)) {
                $updateFields[] = 'description = ?';
                $params[] = $body['description'];
            }
            if (array_key_exists('allowCounterProposals', $body)) {
                $updateFields[] = 'allowCounterProposals = ?';
                $params[] = $body['allowCounterProposals'] ? 1 : 0;
            }
            $updateFields[] = 'updatedAt = NOW()';
            $params[] = $pollId;

            if ($updateFields !== []) {
                $this->pdo->prepare(
                    "UPDATE `Poll` SET " . implode(', ', $updateFields) . " WHERE id = ?"
                )->execute($params);
            }

            // Replace questions if provided
            if (isset($body['questions']) && is_array($body['questions'])) {
                // Get existing question IDs
                $qStmt = $this->pdo->prepare("SELECT id FROM PollQuestion WHERE pollId = ?");
                $qStmt->execute([$pollId]);
                $qIds = array_column($qStmt->fetchAll(), 'id');

                if ($qIds !== []) {
                    $ph = implode(',', array_fill(0, count($qIds), '?'));
                    $this->pdo->prepare("DELETE FROM PollVote WHERE questionId IN ({$ph})")->execute($qIds);
                    $this->pdo->prepare("DELETE FROM PollOption WHERE questionId IN ({$ph})")->execute($qIds);
                    $this->pdo->prepare("DELETE FROM PollQuestion WHERE pollId = ?")->execute([$pollId]);
                }

                $insQ = $this->pdo->prepare(
                    "INSERT INTO PollQuestion (id, pollId, title, type, `order`, createdAt) VALUES (?, ?, ?, ?, ?, NOW())"
                );
                $insO = $this->pdo->prepare(
                    "INSERT INTO PollOption (id, questionId, label, dateValue, `order`, createdAt) VALUES (?, ?, ?, ?, ?, NOW())"
                );

                foreach ($body['questions'] as $i => $q) {
                    $questionId = bin2hex(random_bytes(16));
                    $insQ->execute([$questionId, $pollId, $q['title'] ?? '', $q['type'] ?? 'single', $i]);
                    if (!empty($q['options']) && is_array($q['options'])) {
                        foreach ($q['options'] as $optIdx => $opt) {
                            $optId = bin2hex(random_bytes(16));
                            $dateVal = isset($opt['dateValue']) ? date('Y-m-d H:i:s', strtotime($opt['dateValue'])) : null;
                            $insO->execute([$optId, $questionId, $opt['label'] ?? '', $dateVal, $optIdx]);
                        }
                    }
                }
            }

            // Replace invites if provided
            if (isset($body['invites']) && is_array($body['invites'])) {
                $this->pdo->prepare("DELETE FROM PollInvite WHERE pollId = ?")->execute([$pollId]);
                $insI = $this->pdo->prepare(
                    "INSERT INTO PollInvite (id, pollId, userId, isIndispensable, createdAt) VALUES (?, ?, ?, ?, NOW())"
                );
                foreach ($body['invites'] as $inv) {
                    $invId = bin2hex(random_bytes(16));
                    $insI->execute([$invId, $pollId, $inv['userId'] ?? '', isset($inv['isIndispensable']) && $inv['isIndispensable'] ? 1 : 0]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ResponseFactory::json(['success' => true], 200, $response);
    }

    /**
     * DELETE /polls/{id}
     * Delete poll with all cascade (votes, options, questions, invites).
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $pollId = $args['id'];

        $pollStmt = $this->pdo->prepare("SELECT creatorId FROM `Poll` WHERE id = ? LIMIT 1");
        $pollStmt->execute([$pollId]);
        $poll = $pollStmt->fetch();
        if (!$poll) {
            throw HttpException::notFound();
        }
        if ($poll['creatorId'] !== $user->id) {
            throw HttpException::forbidden();
        }

        $this->pdo->beginTransaction();
        try {
            $qStmt = $this->pdo->prepare("SELECT id FROM PollQuestion WHERE pollId = ?");
            $qStmt->execute([$pollId]);
            $qIds = array_column($qStmt->fetchAll(), 'id');

            if ($qIds !== []) {
                $ph = implode(',', array_fill(0, count($qIds), '?'));
                $this->pdo->prepare("DELETE FROM PollVote WHERE questionId IN ({$ph})")->execute($qIds);
                $this->pdo->prepare("DELETE FROM PollOption WHERE questionId IN ({$ph})")->execute($qIds);
            }

            $this->pdo->prepare("DELETE FROM PollQuestion WHERE pollId = ?")->execute([$pollId]);
            $this->pdo->prepare("DELETE FROM PollInvite WHERE pollId = ?")->execute([$pollId]);
            $this->pdo->prepare("DELETE FROM `Poll` WHERE id = ?")->execute([$pollId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ResponseFactory::noContent($response);
    }
}
