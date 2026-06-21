<?php

namespace Sinclear\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sinclear\Api\Application\ResponseFactory;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\UserService;

final readonly class UserController
{
    public function __construct(
        private UserService $userService,
    ) {}

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $userData = $this->userService->getUser($user->id);

        if ($userData === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $social = $this->userService->getSocialInfo($user->id);
        $contact = $this->userService->getContactInfo($user->id);

        $data = $this->userService->formatUserBase($userData);
        $data['social'] = $social !== null ? $this->userService->formatSocialInfo($social) : [];
        $data['contact'] = $contact !== null ? $this->userService->formatContactInfo($contact) : [];

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    public function meBase(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $userData = $this->userService->getUser($user->id);

        if ($userData === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        return ResponseFactory::json(['data' => $this->userService->formatUserBase($userData)], 200, $response);
    }

    public function meSocial(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $social = $this->userService->getSocialInfo($user->id);

        if ($social === null) {
            return ResponseFactory::json(['data' => []], 200, $response);
        }

        return ResponseFactory::json(['data' => $this->userService->formatSocialInfo($social)], 200, $response);
    }

    public function meContact(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->requireUser($request);
        $contact = $this->userService->getContactInfo($user->id);

        if ($contact === null) {
            return ResponseFactory::json(['data' => []], 200, $response);
        }

        return ResponseFactory::json(['data' => $this->userService->formatContactInfo($contact)], 200, $response);
    }

    /** @param array<string, string> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $requester = $this->requireUser($request);
        $userId = (string) $args['userId'];
        $userData = $this->userService->getUser($userId);

        if ($userData === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $data = $this->userService->formatUserBaseFiltered($userData, $requester);

        $social = $this->userService->getSocialInfo($userId);
        if ($social !== null) {
            $data['social'] = $this->userService->formatSocialInfoFiltered($social, $requester, $userId);
        }

        $contact = $this->userService->getContactInfo($userId);
        if ($contact !== null) {
            $data['contact'] = $this->userService->formatContactInfoFiltered($contact, $requester, $userId);
        }

        return ResponseFactory::json(['data' => $data], 200, $response);
    }

    /** @param array<string, string> $args */
    public function getBase(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $requester = $this->requireUser($request);
        $userId = (string) $args['userId'];
        $userData = $this->userService->getUser($userId);

        if ($userData === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        return ResponseFactory::json(
            ['data' => $this->userService->formatUserBaseFiltered($userData, $requester)],
            200,
            $response,
        );
    }

    /** @param array<string, string> $args */
    public function getSocial(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $requester = $this->requireUser($request);
        $userId = (string) $args['userId'];
        $userData = $this->userService->getUser($userId);

        if ($userData === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $social = $this->userService->getSocialInfo($userId);

        return ResponseFactory::json(
            ['data' => $this->userService->formatSocialInfoFiltered($social, $requester, $userId)],
            200,
            $response,
        );
    }

    /** @param array<string, string> $args */
    public function getContact(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $requester = $this->requireUser($request);
        $userId = (string) $args['userId'];
        $userData = $this->userService->getUser($userId);

        if ($userData === null) {
            return ResponseFactory::json(['error' => 'user_not_found'], 404, $response);
        }

        $contact = $this->userService->getContactInfo($userId);

        return ResponseFactory::json(
            ['data' => $this->userService->formatContactInfoFiltered($contact, $requester, $userId)],
            200,
            $response,
        );
    }

    private function requireUser(ServerRequestInterface $request): AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUser::class);
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authentication required');
        }
        return $user;
    }
}
