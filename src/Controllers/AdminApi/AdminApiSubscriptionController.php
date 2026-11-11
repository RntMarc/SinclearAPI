<?php

namespace Sinclear\Api\Controllers\AdminApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Repository\SubscriptionRepository;
use Sinclear\Api\Services\AdminAuditService;
use Sinclear\Api\Services\SubscriptionService;

final readonly class AdminApiSubscriptionController
{
    use AdminApiConcern;

    public function __construct(
        private SubscriptionService $subscriptionService,
        private SubscriptionRepository $subscriptionRepo,
        private AdminAuditService $auditService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireUser($request);

        return ResponseFactory::json(
            ['data' => $this->subscriptionService->listAll()],
            200,
            $response,
        );
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireUser($request);

        $subscription = $this->subscriptionRepo->findById($args['id']);
        if ($subscription === null) {
            return ResponseFactory::json(['error' => 'subscription_not_found'], 404, $response);
        }

        $participants = array_map(static fn(array $p) => [
            'id' => $p['id'],
            'userId' => $p['userId'] ?? null,
            'isUser' => (bool) ($p['isUser'] ?? false),
            'userName' => $p['userName'] ?? null,
            'userDisplayName' => $p['userDisplayName'] ?? null,
            'hasPaid' => (bool) ($p['hasPaid'] ?? false),
        ], $this->subscriptionRepo->findParticipants($args['id']));

        return ResponseFactory::json([
            'data' => [
                'id' => $subscription['id'],
                'name' => $subscription['name'],
                'billingPeriodStart' => $subscription['billingPeriodStart'],
                'billingPeriodEnd' => $subscription['billingPeriodEnd'],
                'basePrice' => (float) $subscription['basePrice'],
                'participants' => $participants,
            ],
        ], 200, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $name = trim((string) ($body['name'] ?? ''));
        $billingPeriodStart = trim((string) ($body['billingPeriodStart'] ?? ''));
        $billingPeriodEnd = trim((string) ($body['billingPeriodEnd'] ?? ''));
        $basePrice = isset($body['basePrice']) ? (float) $body['basePrice'] : null;

        if ($name === '') {
            return ResponseFactory::json(['error' => 'name_required'], 400, $response);
        }
        if ($billingPeriodStart === '' || $billingPeriodEnd === '') {
            return ResponseFactory::json(['error' => 'billing_period_required'], 400, $response);
        }
        if ($basePrice === null || $basePrice < 0) {
            return ResponseFactory::json(['error' => 'invalid_base_price'], 400, $response);
        }

        try {
            $subscription = $this->subscriptionService->create([
                'name' => $name,
                'billingPeriodStart' => $billingPeriodStart,
                'billingPeriodEnd' => $billingPeriodEnd,
                'basePrice' => $basePrice,
            ]);
            $this->auditService->record($user, 'create', 'subscription', $subscription['id'], $request);
            return ResponseFactory::json(['data' => $subscription], 201, $response);
        } catch (\RuntimeException) {
            return ResponseFactory::json(['error' => 'creation_failed'], 500, $response);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $data = [];

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return ResponseFactory::json(['error' => 'name_required'], 400, $response);
            }
            $data['name'] = $name;
        }
        if (isset($body['billingPeriodStart'])) {
            $data['billingPeriodStart'] = trim((string) $body['billingPeriodStart']);
        }
        if (isset($body['billingPeriodEnd'])) {
            $data['billingPeriodEnd'] = trim((string) $body['billingPeriodEnd']);
        }
        if (isset($body['basePrice'])) {
            $basePrice = (float) $body['basePrice'];
            if ($basePrice < 0) {
                return ResponseFactory::json(['error' => 'invalid_base_price'], 400, $response);
            }
            $data['basePrice'] = $basePrice;
        }

        if ($data === []) {
            return ResponseFactory::json(['error' => 'no_fields_to_update'], 400, $response);
        }

        try {
            $subscription = $this->subscriptionService->update($args['id'], $data);
            $this->auditService->record($user, 'update', 'subscription', $args['id'], $request);
            return ResponseFactory::json(['data' => $subscription], 200, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Subscription not found' ? 404 : 500;
            $error = $e->getMessage() === 'Subscription not found' ? 'subscription_not_found' : 'update_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->subscriptionService->delete($args['id']);
            $this->auditService->record($user, 'delete', 'subscription', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Subscription not found' ? 404 : 500;
            $error = $e->getMessage() === 'Subscription not found' ? 'subscription_not_found' : 'delete_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function addParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $userName = !empty($body['userName']) ? trim((string) $body['userName']) : null;
        $userId = !empty($body['userId']) ? trim((string) $body['userId']) : null;
        $hasPaid = isset($body['hasPaid']) ? (bool) $body['hasPaid'] : false;

        if ($userName === null && $userId === null) {
            return ResponseFactory::json(['error' => 'userName_or_userId_required'], 400, $response);
        }

        try {
            $participant = $this->subscriptionService->addParticipant($args['id'], [
                'userId' => $userId,
                'isUser' => $userId !== null ? 1 : 0,
                'userName' => $userName,
                'hasPaid' => $hasPaid ? 1 : 0,
            ]);
            $this->auditService->record($user, 'add_participant', 'subscription', $args['id'], $request);
            return ResponseFactory::json(['data' => $participant], 201, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Subscription not found' ? 404 : 500;
            $error = $e->getMessage() === 'Subscription not found' ? 'subscription_not_found' : 'add_participant_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function removeParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->subscriptionService->removeParticipant($args['participantId']);
            $this->auditService->record($user, 'remove_participant', 'subscription', $args['id'], $request);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Participant not found' ? 404 : 500;
            $error = $e->getMessage() === 'Participant not found' ? 'participant_not_found' : 'remove_participant_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }

    public function updateParticipant(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->parsedBody($request);

        $hasPaid = isset($body['hasPaid']) ? (bool) $body['hasPaid'] : null;
        if ($hasPaid === null) {
            return ResponseFactory::json(['error' => 'hasPaid_required'], 400, $response);
        }

        try {
            $participant = $this->subscriptionService->updateParticipant($args['participantId'], [
                'hasPaid' => $hasPaid ? 1 : 0,
            ]);
            $this->auditService->record($user, 'update_participant', 'subscription', $args['id'], $request);
            return ResponseFactory::json(['data' => $participant], 200, $response);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage() === 'Participant not found' ? 404 : 500;
            $error = $e->getMessage() === 'Participant not found' ? 'participant_not_found' : 'update_participant_failed';
            return ResponseFactory::json(['error' => $error], $code, $response);
        }
    }
}
