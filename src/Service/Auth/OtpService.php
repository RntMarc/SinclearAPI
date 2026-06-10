<?php

declare(strict_types=1);

namespace Sinclear\Api\Service\Auth;

use Ramsey\Uuid\Uuid;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Service\MailService;

/**
 * Handles email OTP authentication.
 */
final class OtpService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OtpTokenRepository $otpTokenRepository,
        private readonly MailService $mailService,
        private readonly TokenService $tokenService
    ) {
    }

    public function request(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            throw HttpException::badRequest('invalid_credentials');
        }

        $this->createAndSendOtp($email);
    }

    public function requestForEmailChange(string $newEmail): void
    {
        $this->createAndSendOtp($newEmail);
    }

    private function createAndSendOtp(string $email): void
    {
        $this->otpTokenRepository->invalidateUnusedForEmail($email);
        $this->otpTokenRepository->purgeExpired();

        $code = (string) random_int(100000, 999999);
        $this->otpTokenRepository->create([
            'id' => Uuid::uuid4()->toString(),
            'email' => $email,
            'code' => $code,
            'expiresAt' => date('Y-m-d H:i:s', time() + 600),
            'usedAt' => null,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);

        $this->mailService->sendOtp($email, $code);
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, user: array<string, mixed>}
     */
    public function verify(string $email, string $code, ?AuthenticatedUser $currentUser = null): array
    {
        $token = $this->otpTokenRepository->findValid($email, $code);
        if ($token === null || !hash_equals((string) $token['code'], $code)) {
            throw HttpException::badRequest('invalid_credentials');
        }

        $this->otpTokenRepository->markUsed((string) $token['id']);

        if ($currentUser !== null) {
            // Email change verification
            $this->userRepository->update($currentUser->id, ['email' => $email]);
            return $this->tokenService->issueTokenPair($currentUser->id);
        }

        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            throw HttpException::badRequest('invalid_credentials');
        }

        return $this->tokenService->issueTokenPair((string) $user['id']);
    }
}
