<?php

namespace Sinclear\Api\Controllers\AdminApi;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Services\AdminAuditService;

final readonly class AdminApiPollController
{
    use AdminApiConcern;

    public function __construct(
        private PDO $pdo,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        $stmt = $this->pdo->query(
            'SELECT p.id, p.type, p.title, p.status, p.accessMode, p.closesAt, p.createdAt,
                    u.displayName AS creatorDisplayName
             FROM Poll p
             JOIN User u ON u.id = p.creatorId
             ORDER BY p.createdAt DESC
             LIMIT 500'
        );

        $data = array_map(static fn(array $p) => [
            'id' => $p['id'],
            'type' => $p['type'],
            'title' => $p['title'],
            'status' => $p['status'],
            'accessMode' => $p['accessMode'],
            'closesAt' => $p['closesAt'],
            'creatorDisplayName' => $p['creatorDisplayName'],
            'createdAt' => $p['createdAt'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);
        $id = $args['id'];

        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.displayName AS creatorDisplayName
             FROM Poll p JOIN User u ON u.id = p.creatorId
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $poll = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($poll === false) {
            return ResponseFactory::json(['error' => 'poll_not_found'], 404, $response);
        }

        $questionStmt = $this->pdo->prepare('SELECT * FROM PollQuestion WHERE pollId = ? ORDER BY position ASC');
        $questionStmt->execute([$id]);
        $questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

        $optionStmt = $this->pdo->prepare('SELECT * FROM PollOption WHERE pollId = ? ORDER BY position ASC');
        $optionStmt->execute([$id]);
        $options = $optionStmt->fetchAll(PDO::FETCH_ASSOC);

        $inviteStmt = $this->pdo->prepare(
            'SELECT i.*, u.displayName AS userDisplayName FROM PollInvite i
             JOIN User u ON u.id = i.userId WHERE i.pollId = ? ORDER BY i.createdAt ASC'
        );
        $inviteStmt->execute([$id]);
        $invites = $inviteStmt->fetchAll(PDO::FETCH_ASSOC);

        $responseStmt = $this->pdo->prepare(
            'SELECT r.*, u.displayName AS userDisplayName FROM PollResponse r
             JOIN User u ON u.id = r.userId WHERE r.pollId = ? ORDER BY r.createdAt ASC'
        );
        $responseStmt->execute([$id]);
        $responses = $responseStmt->fetchAll(PDO::FETCH_ASSOC);

        $voteStmt = $this->pdo->prepare(
            'SELECT optionId, COUNT(*) AS votes FROM PollVote WHERE pollId = ? GROUP BY optionId'
        );
        $voteStmt->execute([$id]);
        $voteCounts = [];
        foreach ($voteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $voteCounts[$row['optionId']] = (int) $row['votes'];
        }

        $availStmt = $this->pdo->prepare('SELECT COUNT(*) FROM PollAvailabilityVote WHERE pollId = ?');
        $availStmt->execute([$id]);
        $availabilityCount = (int) $availStmt->fetchColumn();

        $questionData = array_map(static fn(array $q) => [
            'id' => $q['id'],
            'type' => $q['type'],
            'title' => $q['title'],
            'isRequired' => (int) $q['isRequired'] === 1,
        ], $questions);

        $optionData = array_map(static function (array $o) use ($voteCounts): array {
            return [
                'id' => $o['id'],
                'label' => $o['label'],
                'allDay' => (int) $o['allDay'] === 1,
                'startDate' => $o['startDate'] ?? null,
                'endDate' => $o['endDate'] ?? null,
                'startAt' => $o['startAt'] ?? null,
                'endAt' => $o['endAt'] ?? null,
                'isCounterProposal' => (int) $o['isCounterProposal'] === 1,
                'votes' => $voteCounts[$o['id']] ?? 0,
            ];
        }, $options);

        $inviteData = array_map(static fn(array $i) => [
            'id' => $i['id'],
            'userId' => $i['userId'],
            'userDisplayName' => $i['userDisplayName'] ?? null,
            'createdAt' => $i['createdAt'],
        ], $invites);

        $responseData = array_map(static fn(array $r) => [
            'id' => $r['id'],
            'userId' => $r['userId'],
            'userDisplayName' => $r['userDisplayName'] ?? null,
            'createdAt' => $r['createdAt'],
        ], $responses);

        return ResponseFactory::json([
            'data' => [
                'id' => $poll['id'],
                'type' => $poll['type'],
                'title' => $poll['title'],
                'description' => $poll['description'] ?? null,
                'status' => $poll['status'],
                'accessMode' => $poll['accessMode'],
                'closesAt' => $poll['closesAt'],
                'creatorDisplayName' => $poll['creatorDisplayName'] ?? null,
                'createdAt' => $poll['createdAt'],
                'questions' => $questionData,
                'options' => $optionData,
                'invites' => $inviteData,
                'responses' => $responseData,
                'availabilityCount' => $availabilityCount,
            ],
        ], 200, $response);
    }

    public function close(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $stmt = $this->pdo->prepare("UPDATE Poll SET status = 'closed', updatedAt = NOW(3) WHERE id = ? AND status = 'open'");
        $stmt->execute([$args['id']]);

        $this->auditService->record($user, 'close', 'poll', $args['id'], $request);

        return ResponseFactory::json(['ok' => true], 200, $response);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        $stmt = $this->pdo->prepare('DELETE FROM Poll WHERE id = ?');
        $stmt->execute([$args['id']]);

        $this->auditService->record($user, 'delete', 'poll', $args['id'], $request);

        return ResponseFactory::noContent($response);
    }
}
