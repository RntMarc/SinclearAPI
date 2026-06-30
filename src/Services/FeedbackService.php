<?php

namespace Sinclear\Api\Services;

use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\FeedbackSuggestionRepository;
use Sinclear\Api\Repository\FeedbackVoteRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class FeedbackService
{
    private const VALID_STATUSES = [
        'submitted', 'planned', 'next', 'in_progress',
        'done', 'cancelled', 'rejected', 'later',
    ];

    public function __construct(
        private FeedbackSuggestionRepository $suggestionRepo,
        private FeedbackVoteRepository $voteRepo,
        private MailerInterface $mailer,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {}

    public function listSuggestions(int $page, int $limit, ?string $userId): array
    {
        $result = $this->suggestionRepo->list($page, $limit, $userId);
        $result['data'] = array_map(
            fn(array $s) => $this->formatSuggestion($s),
            $result['data']
        );
        return $result;
    }

    public function createSuggestion(string $userId, string $title, ?string $description): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new \RuntimeException('title_required');
        }

        $id = $this->suggestionRepo->create([
            'userId' => $userId,
            'title' => $title,
            'description' => $description !== null ? trim($description) : null,
        ]);

        $suggestion = $this->suggestionRepo->findById($id);
        return $this->formatSuggestion($suggestion, $userId);
    }

    public function deleteSuggestion(string $id, string $userId, bool $isAdmin): void
    {
        $suggestion = $this->suggestionRepo->findById($id);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $upvoteCount = $this->suggestionRepo->getUpvoteCount($id);
        if (!$isAdmin && ($suggestion['userId'] !== $userId || $upvoteCount >= 3)) {
            throw new \RuntimeException('forbidden');
        }

        $this->suggestionRepo->delete($id);
    }

    public function vote(string $suggestionId, string $userId): void
    {
        $suggestion = $this->suggestionRepo->findById($suggestionId);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $existing = $this->voteRepo->findBySuggestionAndUser($suggestionId, $userId);
        if ($existing !== null) {
            throw new \RuntimeException('already_voted');
        }

        $this->voteRepo->create($suggestionId, $userId);
    }

    public function removeVote(string $suggestionId, string $userId): void
    {
        $suggestion = $this->suggestionRepo->findById($suggestionId);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $this->voteRepo->delete($suggestionId, $userId);
    }

    public function updateStatus(string $id, string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \RuntimeException('invalid_status');
        }

        $suggestion = $this->suggestionRepo->findById($id);
        if ($suggestion === null) {
            throw new \RuntimeException('suggestion_not_found');
        }

        $this->suggestionRepo->updateStatus($id, $status);
    }

    public function sendBugReport(
        string $userId,
        string $userEmail,
        string $userDisplayName,
        string $text,
        ?string $version,
        ?int $buildNumber,
        ?string $image = null,
    ): void {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('text_required');
        }

        $adminEmail = $this->settings->smtp['admin_email'] ?? '';
        if ($adminEmail === '') {
            throw new \RuntimeException('mail_failed');
        }

        $versionInfo = '';
        if ($version !== null || $buildNumber !== null) {
            $parts = [];
            if ($version !== null) {
                $parts[] = 'Version: ' . htmlspecialchars($version);
            }
            if ($buildNumber !== null) {
                $parts[] = 'Build: ' . $buildNumber;
            }
            $versionInfo = '<li><strong>App-Version:</strong> ' . implode(' / ', $parts) . '</li>';
        }

        $imageHtml = '';
        if ($image !== null) {
            $imageHtml = '<li><strong>Screenshot:</strong> Siehe Anhang</li>';
        }

        $html = '<!DOCTYPE html><html lang="de"><body style="font-family:sans-serif;background:#f4f4f4;padding:2rem;">'
            . '<div style="max-width:600px;margin:0 auto;background:white;padding:2rem;border-radius:12px;">'
            . '<h1 style="font-size:1.25rem;margin-bottom:1rem;">Bug-Report – Sinclear Beyond</h1>'
            . '<ul style="list-style:none;padding:0;margin-bottom:1rem;">'
            . '<li><strong>Nutzer:</strong> ' . htmlspecialchars($userDisplayName) . ' (' . htmlspecialchars($userEmail) . ')</li>'
            . '<li><strong>Nutzer-ID:</strong> ' . htmlspecialchars($userId) . '</li>'
            . $versionInfo
            . $imageHtml
            . '</ul>'
            . '<hr style="border:none;border-top:1px solid #eee;margin:1rem 0;">'
            . '<div style="white-space:pre-wrap;color:#333;">' . htmlspecialchars($text) . '</div>'
            . '</div></body></html>';

        $email = (new Email())
            ->from($this->settings->smtp['from'])
            ->to($adminEmail)
            ->subject('Bug-Report – Sinclear Beyond')
            ->html($html)
            ->text("Bug-Report\n\nNutzer: $userDisplayName ($userEmail)\nID: $userId\n\n$text");

        if ($image !== null) {
            $decoded = $this->validateBugReportImage($image);
            $mimeType = $this->getBugReportImageMimeType($image);
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'png',
            };
            $email->attach(
                body: $decoded,
                filename: "bug-report.$extension",
                mediaType: $mimeType,
            );
        }

        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send bug report', [
                'userId' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('mail_failed');
        }
    }

    private const int MAX_BUG_REPORT_IMAGE_SIZE = 200 * 1024;
    private const int MAX_BUG_REPORT_IMAGE_DIMENSION = 4000;
    private const array ALLOWED_BUG_REPORT_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    private function validateBugReportImage(string $imageData): string
    {
        $decoded = base64_decode($imageData, true);
        if ($decoded === false) {
            throw new \RuntimeException('invalid_image');
        }

        if (strlen($decoded) > self::MAX_BUG_REPORT_IMAGE_SIZE) {
            throw new \RuntimeException('image_too_large');
        }

        $imageInfo = @getimagesizefromstring($decoded);
        if ($imageInfo === false) {
            throw new \RuntimeException('invalid_image_format');
        }

        $mimeType = $imageInfo['mime'];
        if (!in_array($mimeType, self::ALLOWED_BUG_REPORT_IMAGE_MIME_TYPES, true)) {
            throw new \RuntimeException('unsupported_image_format');
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        if ($width > self::MAX_BUG_REPORT_IMAGE_DIMENSION || $height > self::MAX_BUG_REPORT_IMAGE_DIMENSION) {
            throw new \RuntimeException('image_dimensions_too_large');
        }

        return $decoded;
    }

    private function getBugReportImageMimeType(string $imageData): string
    {
        $decoded = base64_decode($imageData, true);
        $imageInfo = @getimagesizefromstring($decoded);
        return $imageInfo['mime'] ?? 'image/png';
    }

    private function formatSuggestion(array $s, ?string $userId = null): array
    {
        $result = [
            'id' => $s['id'],
            'userId' => $s['userId'],
            'title' => $s['title'],
            'description' => $s['description'],
            'status' => $s['status'],
            'upvoteCount' => (int) $s['upvoteCount'],
            'createdAt' => $s['createdAt'],
            'updatedAt' => $s['updatedAt'],
        ];

        if (isset($s['hasVoted'])) {
            $result['hasVoted'] = (bool) $s['hasVoted'];
        } elseif ($userId !== null) {
            $vote = $this->voteRepo->findBySuggestionAndUser($s['id'], $userId);
            $result['hasVoted'] = $vote !== null;
        }

        return $result;
    }
}
