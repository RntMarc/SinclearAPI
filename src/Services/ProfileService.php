<?php

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\ContactInfoRepository;
use Sinclear\Api\Repository\ContactInfoUpdateRepository;
use Sinclear\Api\Repository\OtpTokenRepository;
use Sinclear\Api\Repository\RefreshTokenRepository;
use Sinclear\Api\Repository\SocialInfoRepository;
use Sinclear\Api\Repository\SocialInfoUpdateRepository;
use Sinclear\Api\Repository\UserRepository;
use Sinclear\Api\Repository\UserUpdateRepository;
use Sinclear\Api\Security\Auth\AuthenticatedUser;
use Sinclear\Api\Services\Auth\DiscordOAuthService;
use Sinclear\Api\Services\Auth\OtpService;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class ProfileService
{
    private const int MAX_IMAGE_SIZE_BYTES = 200 * 1024; // 200 KB
    private const int MAX_IMAGE_WIDTH = 1000;
    private const int MAX_IMAGE_HEIGHT = 1000;
    private const array ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private const array CONTACT_FIELDS = [
        'discordHandle' => 'validateNoAt',
        'fluxerHandle' => 'validateNoAt',
        'signalNumber' => 'validateSignal',
        'whatsappNumber' => 'validateWhatsApp',
        'matrixUser' => 'validateMatrixUser',
        'matrixHomeserver' => 'validateDomain',
    ];

    private const array VISIBILITY_FIELDS = [
        'emailVisibility', 'birthdayVisibility',
        'discordVisibility', 'fluxerVisibility', 'matrixVisibility', 'signalVisibility', 'whatsappVisibility',
        'unsplashVisibility', 'instagramVisibility', 'mastodonVisibility', 'pixelfedVisibility',
        'blueskyVisibility', 'youtubeVisibility', 'twitchVisibility',
    ];

    private const array SOCIAL_FIELDS = [
        'unsplashHandle' => 'validateNoAt',
        'instagramHandle' => 'validateNoAt',
        'blueskyHandle' => 'validateBluesky',
        'youtubeHandle' => 'validateNoAt',
        'twitchHandle' => 'validateNoAt',
    ];

    public function __construct(
        private UserRepository $userRepo,
        private UserUpdateRepository $userUpdateRepo,
        private ContactInfoRepository $contactInfoRepo,
        private ContactInfoUpdateRepository $contactInfoUpdateRepo,
        private SocialInfoRepository $socialInfoRepo,
        private SocialInfoUpdateRepository $socialInfoUpdateRepo,
        private OtpService $otpService,
        private OtpTokenRepository $otpTokenRepo,
        private RefreshTokenRepository $refreshTokenRepo,
        private UserService $userService,
        private DiscordOAuthService $discordOAuthService,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private Settings $settings,
        private ImageService $imageService,
    ) {}

    /** @param array<string, mixed> $data */
    public function updateProfile(AuthenticatedUser $user, array $data): array
    {
        $this->logger->debug('ProfileService::updateProfile called', [
            'userId' => $user->id,
            'dataKeys' => array_keys($data),
        ]);

        $userUpdates = [];
        $contactUpdates = [];
        $socialUpdates = [];

        if (array_key_exists('displayName', $data)) {
            $displayName = trim((string) $data['displayName']);
            $this->logger->debug('ProfileService: displayName processing', [
                'original' => $data['displayName'],
                'trimmed' => $displayName,
                'is_empty' => $displayName === '',
            ]);
            if ($displayName === '') {
                $this->logger->debug('ProfileService: throwing invalid_display_name');
                throw new \InvalidArgumentException('invalid_display_name');
            }
            $userUpdates['displayName'] = $displayName;
        }

        if (array_key_exists('birthday', $data)) {
            $birthday = $data['birthday'];
            $this->logger->debug('ProfileService: birthday processing', [
                'raw' => $birthday,
                'type' => gettype($birthday),
                'is_null' => $birthday === null,
                'format_check' => $birthday !== null ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $birthday) ? 'valid' : 'invalid') : 'null',
            ]);
            if ($birthday !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $birthday)) {
                $this->logger->debug('ProfileService: throwing invalid_birthday');
                throw new \InvalidArgumentException('invalid_birthday');
            }
            $userUpdates['birthday'] = $birthday;
        }

        if (array_key_exists('image', $data)) {
            $imageValue = $data['image'];
            $this->logger->debug('ProfileService: image field present', [
                'type' => gettype($imageValue),
                'is_null' => $imageValue === null,
                'is_empty_string' => $imageValue === '',
                'raw_length' => is_string($imageValue) ? strlen($imageValue) : 'N/A',
                'preview' => is_string($imageValue) ? substr($imageValue, 0, 80) . '...' : 'N/A',
            ]);
            if ($imageValue === null || $imageValue === '') {
                $userUpdates['image'] = null;
                $this->logger->debug('ProfileService: image will be removed (set to null)');
            } else {
                $validated = $this->imageService->validate((string) $imageValue);
                $this->logger->debug('ProfileService: image validated successfully', [
                    'stored_length' => strlen($validated),
                ]);
                $userUpdates['image'] = $validated;
            }
        } else {
            $this->logger->debug('ProfileService: no image field in request data');
        }

        foreach (self::CONTACT_FIELDS as $field => $validator) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $value = is_string($value) ? trim($value) : $value;
                $this->{$validator}($value);
                $contactUpdates[$field] = ($value !== null && $value !== '') ? $value : null;
            }
        }

        foreach (self::SOCIAL_FIELDS as $field => $validator) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $value = is_string($value) ? trim($value) : $value;
                $this->{$validator}($value);
                $socialUpdates[$field] = ($value !== null && $value !== '') ? $value : null;
            }
        }

        if (array_key_exists('mastodonUser', $data) || array_key_exists('mastodonServer', $data)) {
            $mastoUser = array_key_exists('mastodonUser', $data)
                ? trim((string) $data['mastodonUser'])
                : $this->getCurrentFediverseUser($user->id, 'mastodonHandle');
            $mastoServer = array_key_exists('mastodonServer', $data)
                ? trim((string) $data['mastodonServer'])
                : $this->getCurrentFediverseServer($user->id, 'mastodonHandle');
            $socialUpdates['mastodonHandle'] = $this->combineFediverseHandle($mastoUser, $mastoServer);
        }

        if (array_key_exists('pixelfedUser', $data) || array_key_exists('pixelfedServer', $data)) {
            $pixelUser = array_key_exists('pixelfedUser', $data)
                ? trim((string) $data['pixelfedUser'])
                : $this->getCurrentFediverseUser($user->id, 'pixelfedHandle');
            $pixelServer = array_key_exists('pixelfedServer', $data)
                ? trim((string) $data['pixelfedServer'])
                : $this->getCurrentFediverseServer($user->id, 'pixelfedHandle');
            $socialUpdates['pixelfedHandle'] = $this->combineFediverseHandle($pixelUser, $pixelServer);
        }

        if (!empty($userUpdates)) {
            $this->logger->debug('ProfileService: applying userUpdates', [
                'fields' => array_keys($userUpdates),
            ]);
            foreach ($userUpdates as $field => $value) {
                match ($field) {
                    'displayName' => $this->userUpdateRepo->updateDisplayName($user->id, $value),
                    'birthday' => $this->userUpdateRepo->updateBirthday($user->id, $value),
                    'image' => $this->userUpdateRepo->updateImage($user->id, $value),
                };
            }
            $this->logger->debug('ProfileService: userUpdates applied successfully');
        } else {
            $this->logger->debug('ProfileService: no userUpdates to apply');
        }

        if (!empty($contactUpdates)) {
            $this->contactInfoUpdateRepo->upsert($user->id, $contactUpdates);
        }

        if (!empty($socialUpdates)) {
            $this->socialInfoUpdateRepo->upsert($user->id, $socialUpdates);
        }

        return $this->buildProfileResponse($user->id);
    }

    public function requestEmailChange(AuthenticatedUser $user, string $newEmail): void
    {
        $newEmail = trim($newEmail);

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('invalid_email');
        }

        if ($newEmail === $user->email) {
            throw new \InvalidArgumentException('email_unchanged');
        }

        $existingUser = $this->userRepo->findByEmail($newEmail);
        if ($existingUser !== null) {
            throw new \InvalidArgumentException('email_already_taken');
        }

        if (!$this->otpService->canRequestCode($newEmail)) {
            throw new \InvalidArgumentException('too_many_requests');
        }

        $code = $this->otpService->generateCode();
        $this->otpService->sendOtpEmail($newEmail, $code);
        $this->otpService->storeCode($newEmail, $code);
    }

    public function verifyEmailChange(AuthenticatedUser $user, string $code, string $newEmail): void
    {
        $newEmail = trim($newEmail);

        if (!preg_match('/^\d{6}$/', $code)) {
            throw new \InvalidArgumentException('invalid_code');
        }

        $otpToken = $this->otpTokenRepo->findValid($newEmail, $code);
        if ($otpToken === null) {
            throw new \InvalidArgumentException('invalid_or_expired_code');
        }

        $this->otpTokenRepo->markUsed($otpToken['id']);

        $oldEmail = $user->email;
        $this->userUpdateRepo->updateEmail($user->id, $newEmail);

        $now = new DateTimeImmutable();
        $this->refreshTokenRepo->revokeAllForUser($user->id, $now);

        $this->sendEmailChangeNotification($oldEmail, $newEmail);
        $this->sendAdminAlert('email_change', [
            'userId' => $user->id,
            'oldEmail' => $oldEmail,
            'newEmail' => $newEmail,
        ]);
    }

    public function startDiscordRelink(AuthenticatedUser $user): array
    {
        return $this->discordOAuthService->generateOAuthUrl($user->id);
    }

    public function processDiscordCallback(string $code, string $state): array
    {
        return $this->discordOAuthService->processRelinkCallback($code, $state);
    }

    public function verifyDiscordRelink(AuthenticatedUser $user, string $code): void
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new \InvalidArgumentException('invalid_code');
        }

        $otpToken = $this->otpTokenRepo->findValidByCode($code);
        if ($otpToken === null) {
            throw new \InvalidArgumentException('invalid_or_expired_code');
        }

        $metadata = json_decode($otpToken['email'], true);
        if (!is_array($metadata) || ($metadata['type'] ?? '') !== 'discord_relink') {
            throw new \InvalidArgumentException('invalid_code');
        }

        if ((string) $metadata['userId'] !== $user->id) {
            throw new \InvalidArgumentException('invalid_code');
        }

        $this->otpTokenRepo->markUsed($otpToken['id']);

        $newDiscordId = $metadata['newDiscordId'];
        $this->userUpdateRepo->updateDiscordId($user->id, $newDiscordId);

        $this->sendDiscordRelinkNotification($user->email);
        $this->sendAdminAlert('discord_relink', [
            'userId' => $user->id,
            'email' => $user->email,
            'newDiscordId' => $newDiscordId,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateVisibility(AuthenticatedUser $user, array $data): void
    {
        $userUpdates = [];
        $contactUpdates = [];
        $socialUpdates = [];

        foreach ($data as $field => $value) {
            if (!in_array($field, self::VISIBILITY_FIELDS, true)) {
                throw new \InvalidArgumentException('invalid_field');
            }
            $intValue = is_numeric($value) ? (int) $value : -1;
            if (!in_array($intValue, [0, 1, 2], true)) {
                throw new \InvalidArgumentException('invalid_visibility_value');
            }
            match (true) {
                in_array($field, ['emailVisibility', 'birthdayVisibility']) => $userUpdates[$field] = $intValue,
                in_array($field, ['discordVisibility', 'fluxerVisibility', 'matrixVisibility', 'signalVisibility', 'whatsappVisibility']) => $contactUpdates[$field] = $intValue,
                default => $socialUpdates[$field] = $intValue,
            };
        }

        foreach ($userUpdates as $field => $value) {
            $this->userUpdateRepo->updateField($user->id, $field, $value);
        }
        if ($contactUpdates !== []) {
            $this->contactInfoUpdateRepo->upsert($user->id, $contactUpdates);
        }
        if ($socialUpdates !== []) {
            $this->socialInfoUpdateRepo->upsert($user->id, $socialUpdates);
        }
    }

    public function completeOnboarding(AuthenticatedUser $user): void
    {
        $this->userUpdateRepo->updateField($user->id, 'onboardingCompleted', 1);
    }

    private function buildProfileResponse(string $userId): array
    {
        $userData = $this->userRepo->findById($userId);
        $social = $this->socialInfoRepo->findByUserId($userId);
        $contact = $this->contactInfoRepo->findByUserId($userId);

        $data = $this->userService->formatUserBase($userData);
        $data['social'] = $social !== null ? $this->userService->formatSocialInfo($social) : null;
        $data['contact'] = $contact !== null ? $this->userService->formatContactInfo($contact) : null;

        $this->logger->debug('buildProfileResponse', [
            'image_returned' => isset($data['image']),
            'image_length' => isset($data['image']) && is_string($data['image']) ? strlen($data['image']) : 0,
            'image_preview' => isset($data['image']) && is_string($data['image']) ? substr($data['image'], 0, 80) . '...' : 'null or not set',
        ]);

        return $data;
    }

    private function sendEmailChangeNotification(string $oldEmail, string $newEmail): void
    {
        $email = (new Email())
            ->from($this->settings->smtp['from'])
            ->to($oldEmail)
            ->subject('E-Mail-Adresse geändert – Sinclear Beyond')
            ->html(
                strtr(
                    file_get_contents(__DIR__ . '/../../templates/email-change-notification.php') ?: '',
                    ['{{newEmail}}' => htmlspecialchars($newEmail)],
                )
            )
            ->text("Deine E-Mail-Adresse wurde zu $newEmail geändert.\n\nFalls du diese Änderung nicht selbst vorgenommen hast, kontaktiere bitte umgehend den Support.");

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send email change notification', [
                'oldEmail' => $oldEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendDiscordRelinkNotification(string $userEmail): void
    {
        $email = (new Email())
            ->from($this->settings->smtp['from'])
            ->to($userEmail)
            ->subject('Discord-Account geändert – Sinclear Beyond')
            ->html(
                '<!DOCTYPE html><html lang="de"><body style="font-family:sans-serif;background:#f4f4f4;padding:2rem;">'
                . '<div style="max-width:400px;margin:0 auto;background:white;padding:2rem;border-radius:12px;">'
                . '<h1 style="font-size:1.25rem;margin-bottom:1rem;">Discord-Account geändert</h1>'
                . '<p style="color:#555;">Dein verknüpfter Discord-Account wurde aktualisiert.</p>'
                . '<p style="color:#888;font-size:0.85rem;">Falls du diese Änderung nicht selbst vorgenommen hast, kontaktiere bitte umgehend den Support.</p>'
                . '</div></body></html>'
            )
            ->text("Dein verknüpfter Discord-Account wurde geändert.\n\nFalls du diese Änderung nicht selbst vorgenommen hast, kontaktiere bitte umgehend den Support.");

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Discord relink notification', [
                'email' => $userEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function sendAdminAlert(string $changeType, array $context): void
    {
        $adminEmail = $this->settings->smtp['admin_email'] ?? '';
        if ($adminEmail === '') {
            return;
        }

        $subject = match ($changeType) {
            'email_change' => 'Sicherheitshinweis: E-Mail-Adresse geändert – Sinclear Beyond',
            'discord_relink' => 'Sicherheitshinweis: Discord-Account geändert – Sinclear Beyond',
            default => 'Sicherheitshinweis: Profiländerung – Sinclear Beyond',
        };

        $contextLines = '';
        foreach ($context as $key => $value) {
            $contextLines .= '<li><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars((string) $value) . '</li>';
        }

        $html = '<!DOCTYPE html><html lang="de"><body style="font-family:sans-serif;background:#f4f4f4;padding:2rem;">'
            . '<div style="max-width:400px;margin:0 auto;background:white;padding:2rem;border-radius:12px;">'
            . '<h1 style="font-size:1.25rem;margin-bottom:1rem;">Sicherheitshinweis</h1>'
            . '<p style="color:#555;">Folgende Änderung wurde an einem Benutzerkonto vorgenommen:</p>'
            . '<ul>' . $contextLines . '</ul>'
            . '</div></body></html>';

        $text = strip_tags($contextLines);

        $email = (new Email())
            ->from($this->settings->smtp['from'])
            ->to($adminEmail)
            ->subject($subject)
            ->html($html)
            ->text($text);

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send admin alert', [
                'changeType' => $changeType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function combineFediverseHandle(string $user, string $server): ?string
    {
        if ($user === '' && $server === '') {
            return null;
        }
        if ($user === '') {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
        $this->validateFediverseUser($user);
        $this->validateDomain($server);
        return $user . '@' . $server;
    }

    private function getCurrentFediverseUser(string $userId, string $handleField): string
    {
        $social = $this->socialInfoRepo->findByUserId($userId);
        if ($social === null || empty($social[$handleField])) {
            return '';
        }
        $parts = explode('@', $social[$handleField], 2);
        return $parts[0] ?? '';
    }

    private function getCurrentFediverseServer(string $userId, string $handleField): string
    {
        $social = $this->socialInfoRepo->findByUserId($userId);
        if ($social === null || empty($social[$handleField])) {
            return '';
        }
        $parts = explode('@', $social[$handleField], 2);
        return $parts[1] ?? '';
    }

    private function validateNoAt(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || str_contains($value, '@')) {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
    }

    private function validateSignal(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_.]+\.[0-9]{2}$/', $value)) {
            throw new \InvalidArgumentException('invalid_signal_number');
        }
    }

    private function validateWhatsApp(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || !str_starts_with($value, '+') || !preg_match('/^\+[0-9]+$/', $value)) {
            throw new \InvalidArgumentException('invalid_whatsapp_number');
        }
    }

    private function validateMatrixUser(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || str_contains($value, '@') || str_contains($value, ':')) {
            throw new \InvalidArgumentException('invalid_matrix_user');
        }
    }

    private function validateFediverseUser(string $value): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
        if (str_contains($value, '@') || str_contains($value, ':')) {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
    }

    private function validateDomain(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || !preg_match('/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/', $value)) {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
    }

    private function validateBluesky(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value) || str_contains($value, '@')) {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
        if (!preg_match('/^[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+$/', $value)) {
            throw new \InvalidArgumentException('invalid_handle_format');
        }
    }

    private function validateProfileImage(string $imageData): string
    {
        $this->logger->debug('validateProfileImage: start', [
            'base64_length' => strlen($imageData),
        ]);

        if (!is_string($imageData) || $imageData === '') {
            $this->logger->debug('validateProfileImage: failed - empty or non-string input');
            throw new \InvalidArgumentException('invalid_image');
        }

        $decoded = base64_decode($imageData, true);
        if ($decoded === false) {
            $this->logger->debug('validateProfileImage: failed - base64 decode returned false');
            throw new \InvalidArgumentException('invalid_image_encoding');
        }

        $decodedSize = strlen($decoded);
        $this->logger->debug('validateProfileImage: decoded', [
            'decoded_bytes' => $decodedSize,
            'max_allowed' => self::MAX_IMAGE_SIZE_BYTES,
        ]);

        if ($decodedSize > self::MAX_IMAGE_SIZE_BYTES) {
            $this->logger->debug('validateProfileImage: failed - image too large', [
                'decoded_bytes' => $decodedSize,
                'max_allowed' => self::MAX_IMAGE_SIZE_BYTES,
            ]);
            throw new \InvalidArgumentException('image_too_large');
        }

        $imageInfo = @getimagesizefromstring($decoded);
        if ($imageInfo === false) {
            $this->logger->debug('validateProfileImage: failed - getimagesizefromstring returned false');
            throw new \InvalidArgumentException('invalid_image_format');
        }

        $mimeType = $imageInfo['mime'];
        $width = $imageInfo[0];
        $height = $imageInfo[1];

        $this->logger->debug('validateProfileImage: image info', [
            'mime' => $mimeType,
            'width' => $width,
            'height' => $height,
        ]);

        if (!in_array($mimeType, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
            $this->logger->debug('validateProfileImage: failed - unsupported mime type', [
                'mime' => $mimeType,
                'allowed' => self::ALLOWED_IMAGE_MIME_TYPES,
            ]);
            throw new \InvalidArgumentException('unsupported_image_format');
        }

        if ($width > self::MAX_IMAGE_WIDTH || $height > self::MAX_IMAGE_HEIGHT) {
            $this->logger->debug('validateProfileImage: failed - dimensions too large', [
                'width' => $width,
                'height' => $height,
                'max_width' => self::MAX_IMAGE_WIDTH,
                'max_height' => self::MAX_IMAGE_HEIGHT,
            ]);
            throw new \InvalidArgumentException('image_dimensions_too_large');
        }

        $this->logger->debug('validateProfileImage: validation passed');
        return $imageData;
    }
}
