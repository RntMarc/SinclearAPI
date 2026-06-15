<?php

namespace Sinclear\Api\Services\Auth;

use DateTimeImmutable;
use Sinclear\Api\Repository\OtpTokenRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class OtpService
{
    private const int CODE_LENGTH = 6;
    private const int CODE_TTL = 600;
    private const int RATE_LIMIT_WINDOW = 60;
    private const int MAX_REQUESTS_PER_WINDOW = 3;

    public function __construct(
        private OtpTokenRepository $otpTokenRepo,
        private MailerInterface $mailer,
        private string $fromAddress,
    ) {}

    public function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    public function getCodeTtl(): int
    {
        return self::CODE_TTL;
    }

    public function canRequestCode(string $email): bool
    {
        $since = new DateTimeImmutable("-" . self::RATE_LIMIT_WINDOW . " seconds");
        $count = $this->otpTokenRepo->countRecentByEmail($email, $since);
        return $count < self::MAX_REQUESTS_PER_WINDOW;
    }

    public function sendOtpEmail(string $email, string $code): void
    {
        $emailMessage = (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject('Dein Login-Code für Sinclear Beyond')
            ->html(
                strtr(
                    file_get_contents(__DIR__ . '/../../../templates/otp-email.php') ?: '',
                    ['{{code}}' => $code],
                )
            )
            ->text("Dein Login-Code für Sinclear Beyond: $code\n\nDer Code ist 10 Minuten gültig.");

        $this->mailer->send($emailMessage);
    }

    public function storeCode(string $email, string $code): void
    {
        $expiresAt = new DateTimeImmutable('+' . self::CODE_TTL . ' seconds');
        $this->otpTokenRepo->create($email, $code, $expiresAt);
    }

    public function isCodeExpired(\DateTimeInterface $expiresAt): bool
    {
        return new DateTimeImmutable() > $expiresAt;
    }
}
