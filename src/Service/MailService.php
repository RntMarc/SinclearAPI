<?php

declare(strict_types=1);

namespace Sinclear\Api\Service;

use Sinclear\Api\Application\Settings;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * Sends transactional emails via SMTP.
 */
final class MailService
{
    public function __construct(
        private readonly Settings $settings
    ) {
    }

    public function sendOtp(string $to, string $code): void
    {
        $smtp = $this->settings->get('smtp', []);
        if (empty($smtp['host'])) {
            if ($this->settings->isDebug()) {
                return;
            }
            throw new \RuntimeException('SMTP not configured');
        }

        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            urlencode((string) $smtp['user']),
            urlencode((string) $smtp['password']),
            $smtp['host'],
            $smtp['port']
        );

        $mailer = new Mailer(Transport::fromDsn($dsn));
        $email = (new Email())
            ->from((string) $smtp['from'])
            ->to($to)
            ->subject('Sinclear Beyond – Anmeldecode')
            ->text("Dein Anmeldecode lautet: {$code}\n\nDer Code ist 10 Minuten gültig.");

        $mailer->send($email);
    }
}
