<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\PollAppointmentService;

final readonly class PollAppointmentController
{
    private const array ERROR_MAP = [
        'not_found' => ['error' => 'not_found', 'status' => 404],
        'forbidden' => ['error' => 'forbidden', 'status' => 403],
        'invalid_type' => ['error' => 'invalid_type', 'status' => 400],
        'invalid_answer' => ['error' => 'invalid_answer', 'status' => 400],
        'invalid_option' => ['error' => 'invalid_option', 'status' => 400],
        'option_not_found' => ['error' => 'option_not_found', 'status' => 404],
        'counter_proposals_disabled' => ['error' => 'counter_proposals_disabled', 'status' => 403],
        'time_forbidden' => ['error' => 'time_forbidden', 'status' => 400],
        'date_forbidden' => ['error' => 'date_forbidden', 'status' => 400],
        'poll_closed' => ['error' => 'poll_closed', 'status' => 409],
    ];

    public function __construct(
        private PollAppointmentService $appointmentService,
    ) {}

    public function addCounterProposal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $option = $this->appointmentService->addCounterProposal($user, $args['id'], $this->body($request));
            return ResponseFactory::json(['data' => $option], 201, $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function deleteCounterProposal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $this->appointmentService->removeCounterProposal($user, $args['id'], $args['optionId']);
            return ResponseFactory::noContent($response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function listAvailability(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);

        try {
            $result = $this->appointmentService->listAvailability($user, $args['id']);
            return ResponseFactory::paginated($result['data'], $result['meta'], $response);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function setAvailability(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $availability = is_array($body['availability'] ?? null) ? $body['availability'] : [];

        try {
            return ResponseFactory::json(
                ['data' => $this->appointmentService->setAvailability($user, $args['id'], $availability)],
                200,
                $response,
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    public function finalize(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request);
        $body = $this->body($request);
        $optionId = isset($body['optionId']) ? (string) $body['optionId'] : '';

        try {
            return ResponseFactory::json(
                ['data' => $this->appointmentService->finalize($user, $args['id'], $optionId)],
                200,
                $response,
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), $response);
        }
    }

    private function errorResponse(string $message, ResponseInterface $response): ResponseInterface
    {
        $mapped = self::ERROR_MAP[$message] ?? null;
        if ($mapped !== null) {
            return ResponseFactory::json(['error' => $mapped['error']], $mapped['status'], $response);
        }
        return ResponseFactory::json(['error' => 'internal_error'], 500, $response);
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }
}
