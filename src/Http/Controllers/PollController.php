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
        private readonly PollService $pollService
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

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw HttpException::unauthorized();
        }
        return $user;
    }
}
