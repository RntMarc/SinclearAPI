<?php

declare(strict_types=1);

namespace Sinclear\Api\Service\Auth;

use Ramsey\Uuid\Uuid;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Dto\SensitiveFields;
use Sinclear\Api\Exception\HttpException;
use Sinclear\Api\Repository\PasskeyRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\WebauthnChallengeRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
/**
 * WebAuthn passkey registration and authentication.
 */
final class PasskeyService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly UserRepository $userRepository,
        private readonly PasskeyRepository $passkeyRepository,
        private readonly WebauthnChallengeRepository $challengeRepository,
        private readonly TokenService $tokenService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function registerBegin(AuthenticatedUser $user): array
    {
        $this->challengeRepository->purgeExpired();
        $dbUser = $this->userRepository->findById($user->id);
        if ($dbUser === null) {
            throw HttpException::notFound();
        }

        $existing = $this->passkeyRepository->findByUserId($user->id);
        $exclude = array_map(
            static fn (array $pk): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                base64_decode(strtr($pk['credentialId'], '-_', '+/') . str_repeat('=', (4 - strlen($pk['credentialId']) % 4) % 4), true) ?: $pk['credentialId'],
                $pk['transports'] ? json_decode((string) $pk['transports'], true) : []
            ),
            $existing
        );

        $challenge = random_bytes(32);
        $challengeB64 = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');

        $this->challengeRepository->create([
            'id' => Uuid::uuid4()->toString(),
            'challenge' => $challengeB64,
            'userId' => $user->id,
            'expiresAt' => date('Y-m-d H:i:s', time() + 300),
            'createdAt' => date('Y-m-d H:i:s'),
        ]);

        $rp = PublicKeyCredentialRpEntity::create(
            (string) $this->settings->get('webauthn.rp_name', 'Sinclear Beyond'),
            (string) $this->settings->get('webauthn.rp_id', 'localhost')
        );

        $userEntity = PublicKeyCredentialUserEntity::create(
            (string) $dbUser['displayName'],
            $user->id,
            (string) $dbUser['email']
        );

        $options = PublicKeyCredentialCreationOptions::create(
            $rp,
            $userEntity,
            $challenge,
            [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude
        );

        return json_decode(json_encode($options), true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function registerFinish(AuthenticatedUser $user, array $body, ?string $name = null): array
    {
        $clientData = json_decode(
            base64_decode(strtr((string) ($body['response']['clientDataJSON'] ?? ''), '-_', '+/'), true) ?: '{}',
            true
        );
        $challenge = (string) ($clientData['challenge'] ?? '');
        $stored = $this->challengeRepository->findValidChallenge($challenge);
        if ($stored === null || (string) ($stored['userId'] ?? '') !== $user->id) {
            throw HttpException::badRequest('invalid_challenge');
        }

        $credentialId = (string) ($body['id'] ?? '');
        $publicKey = (string) ($body['response']['attestationObject'] ?? '');
        $transports = isset($body['transports']) ? json_encode($body['transports']) : null;

        $this->passkeyRepository->create([
            'id' => Uuid::uuid4()->toString(),
            'userId' => $user->id,
            'credentialId' => $credentialId,
            'publicKey' => $publicKey,
            'counter' => 0,
            'transports' => $transports,
            'name' => $name ?? 'Passkey',
            'createdAt' => date('Y-m-d H:i:s'),
            'lastUsedAt' => null,
        ]);

        $this->challengeRepository->deleteByChallenge($challenge);

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function loginBegin(): array
    {
        $this->challengeRepository->purgeExpired();
        $challenge = random_bytes(32);
        $challengeB64 = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');

        $this->challengeRepository->create([
            'id' => Uuid::uuid4()->toString(),
            'challenge' => $challengeB64,
            'userId' => null,
            'expiresAt' => date('Y-m-d H:i:s', time() + 300),
            'createdAt' => date('Y-m-d H:i:s'),
        ]);

        $options = PublicKeyCredentialRequestOptions::create(
            $challenge,
            (string) $this->settings->get('webauthn.rp_id', 'localhost'),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED
        );

        return json_decode(json_encode($options), true);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{accessToken: string, refreshToken: string, expiresIn: int, user: array<string, mixed>}
     */
    public function loginFinish(array $body): array
    {
        $clientData = json_decode(
            base64_decode(strtr((string) ($body['response']['clientDataJSON'] ?? ''), '-_', '+/'), true) ?: '{}',
            true
        );
        $challenge = (string) ($clientData['challenge'] ?? '');
        $stored = $this->challengeRepository->findValidChallenge($challenge);
        if ($stored === null) {
            throw HttpException::badRequest('invalid_challenge');
        }

        $credentialId = (string) ($body['id'] ?? '');
        $passkey = $this->passkeyRepository->findByCredentialId($credentialId);
        if ($passkey === null) {
            throw HttpException::badRequest('invalid_credentials');
        }

        $newCounter = (int) ($passkey['counter'] ?? 0) + 1;
        $this->passkeyRepository->updateCounter((string) $passkey['id'], $newCounter);
        $this->challengeRepository->deleteByChallenge($challenge);

        return $this->tokenService->issueTokenPair((string) $passkey['userId']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPasskeys(AuthenticatedUser $user): array
    {
        $rows = $this->passkeyRepository->findByUserId($user->id);
        return array_map(
            static fn (array $row): array => SensitiveFields::strip($row, ['publicKey']),
            $rows
        );
    }

    public function deletePasskey(AuthenticatedUser $user, string $id): void
    {
        $passkey = $this->passkeyRepository->findById($id);
        if ($passkey === null) {
            throw HttpException::notFound();
        }
        if ((string) $passkey['userId'] !== $user->id && !$user->isAdmin) {
            throw HttpException::forbidden();
        }
        $this->passkeyRepository->delete($id);
    }
}
