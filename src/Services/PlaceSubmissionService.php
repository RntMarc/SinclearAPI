<?php

namespace Sinclear\Api\Services;

use Sinclear\Api\Repository\DiscoverPlaceSubmissionRepository;
use Sinclear\Api\Repository\DiscoverReviewRepository;
use Sinclear\Api\Repository\UserRepository;

final readonly class PlaceSubmissionService
{
    public function __construct(
        private DiscoverPlaceSubmissionRepository $submissionRepo,
        private ExploreService $exploreService,
        private DiscoverReviewRepository $reviewRepo,
        private NotificationService $notificationService,
        private ImageService $imageService,
        private UserRepository $userRepo,
    ) {}

    public function createSubmission(string $userId, array $body): array
    {
        if (empty($body['name'])) {
            throw new \InvalidArgumentException('Name is required');
        }
        if (!isset($body['latitude']) || !isset($body['longitude'])) {
            throw new \InvalidArgumentException('Latitude and longitude are required');
        }
        if (!isset($body['rating']) || (int) $body['rating'] < 1 || (int) $body['rating'] > 5) {
            throw new \InvalidArgumentException('Rating is required and must be 1-5');
        }

        $lat = (float) $body['latitude'];
        $lon = (float) $body['longitude'];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }

        $data = [
            'userId' => $userId,
            'name' => trim($body['name']),
            'address' => !empty($body['address']) ? trim($body['address']) : null,
            'latitude' => $lat,
            'longitude' => $lon,
            'photo' => $this->validatePhoto($body['photo'] ?? null),
            'mapLink' => !empty($body['mapLink']) ? trim($body['mapLink']) : null,
            'website' => !empty($body['website']) ? trim($body['website']) : null,
            'rating' => (int) $body['rating'],
            'comment' => isset($body['comment']) && is_string($body['comment']) ? trim($body['comment']) : null,
            'note' => !empty($body['note']) ? trim($body['note']) : null,
        ];

        $id = $this->submissionRepo->create($data);
        $submission = $this->submissionRepo->findById($id);

        $this->notifyUserSubmissionCreated($userId, $id, $data['name']);
        $this->notifyAdminsNewSubmission($id, $data['name']);

        return $this->format($submission);
    }

    public function updateSubmission(string $id, string $userId, array $body): array
    {
        $existing = $this->submissionRepo->findById($id);
        if ($existing === null) {
            throw new \RuntimeException('Submission not found');
        }
        if ($existing['userId'] !== $userId) {
            throw new \RuntimeException('Forbidden');
        }
        if ($existing['status'] !== 'pending') {
            throw new \RuntimeException('Only pending submissions can be edited');
        }

        $update = [];

        if (isset($body['name'])) {
            if (empty($body['name'])) {
                throw new \InvalidArgumentException('Name cannot be empty');
            }
            $update['name'] = trim($body['name']);
        }

        if (isset($body['address'])) {
            $update['address'] = !empty($body['address']) ? trim($body['address']) : null;
        }

        if (isset($body['latitude']) && isset($body['longitude'])) {
            $lat = (float) $body['latitude'];
            $lon = (float) $body['longitude'];
            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                throw new \InvalidArgumentException('Invalid coordinates');
            }
            $update['latitude'] = $lat;
            $update['longitude'] = $lon;
        }

        if (array_key_exists('photo', $body)) {
            $update['photo'] = $this->validatePhoto($body['photo']);
        }

        if (array_key_exists('mapLink', $body)) {
            $update['mapLink'] = !empty($body['mapLink']) ? trim($body['mapLink']) : null;
        }

        if (array_key_exists('website', $body)) {
            $update['website'] = !empty($body['website']) ? trim($body['website']) : null;
        }

        if (isset($body['rating'])) {
            $rating = (int) $body['rating'];
            if ($rating < 1 || $rating > 5) {
                throw new \InvalidArgumentException('Rating must be 1-5');
            }
            $update['rating'] = $rating;
        }

        if (array_key_exists('comment', $body)) {
            $update['comment'] = isset($body['comment']) && is_string($body['comment']) ? trim($body['comment']) : null;
        }

        if (array_key_exists('note', $body)) {
            $update['note'] = !empty($body['note']) ? trim($body['note']) : null;
        }

        if (empty($update)) {
            throw new \InvalidArgumentException('No fields to update');
        }

        $this->submissionRepo->update($id, $update);
        $submission = $this->submissionRepo->findById($id);

        return $this->format($submission);
    }

    public function approveSubmission(string $id, int $osmId, string $osmType, string $adminNote): array
    {
        $submission = $this->submissionRepo->findById($id);
        if ($submission === null) {
            throw new \RuntimeException('Submission not found');
        }
        if ($submission['status'] !== 'pending') {
            throw new \RuntimeException('Submission is not pending');
        }

        $place = $this->exploreService->createPlace($osmId, $osmType, $submission['userId']);

        if ($submission['rating'] !== null) {
            $this->reviewRepo->create([
                'placeId' => $place['id'],
                'userId' => $submission['userId'],
                'rating' => (int) $submission['rating'],
                'comment' => $submission['comment'] ?? null,
            ]);
        }

        $this->submissionRepo->approve($id, $adminNote, $place['id']);

        $this->notifyUserSubmissionApproved($submission['userId'], $id, $submission['name'], $place['id']);

        return [
            'placeId' => $place['id'],
            'status' => 'transferred',
        ];
    }

    public function rejectSubmission(string $id, string $adminNote): array
    {
        $submission = $this->submissionRepo->findById($id);
        if ($submission === null) {
            throw new \RuntimeException('Submission not found');
        }
        if ($submission['status'] !== 'pending') {
            throw new \RuntimeException('Submission is not pending');
        }

        $this->submissionRepo->reject($id, $adminNote);
        $updated = $this->submissionRepo->findById($id);

        $this->notifyUserSubmissionRejected($submission['userId'], $id, $submission['name']);

        return $this->format($updated);
    }

    public function listUserSubmissions(string $userId, int $page, int $limit): array
    {
        $result = $this->submissionRepo->findByUserId($userId, $page, $limit);
        $result['data'] = array_map(fn(array $s) => $this->format($s), $result['data']);
        return $result;
    }

    public function listAllSubmissions(?string $status, int $page, int $limit): array
    {
        $result = $this->submissionRepo->findAll($status, $page, $limit);
        $result['data'] = array_map(fn(array $s) => $this->format($s), $result['data']);
        return $result;
    }

    public function getSubmission(string $id): ?array
    {
        $submission = $this->submissionRepo->findById($id);
        return $submission !== null ? $this->format($submission) : null;
    }

    public function getStatusCounts(): array
    {
        return $this->submissionRepo->allStatusCounts();
    }

    private function validatePhoto(mixed $photo): ?string
    {
        if ($photo === null || $photo === '' || $photo === false) {
            return null;
        }
        return $this->imageService->validate((string) $photo);
    }

    private function notifyUserSubmissionCreated(string $userId, string $submissionId, string $name): void
    {
        $this->notificationService->createNotification(
            userId: $userId,
            code: 'submission.created',
            payload: [
                'submissionId' => $submissionId,
                'name' => $name,
                'status' => 'pending',
            ],
        );
    }

    private function notifyAdminsNewSubmission(string $submissionId, string $name): void
    {
        $adminIds = $this->userRepo->findAdminIds();
        foreach ($adminIds as $adminId) {
            $this->notificationService->createNotification(
                userId: $adminId,
                code: 'submission.new',
                payload: [
                    'submissionId' => $submissionId,
                    'name' => $name,
                    'deepLink' => 'entdecken',
                ],
            );
        }
    }

    private function notifyUserSubmissionApproved(string $userId, string $submissionId, string $name, string $placeId): void
    {
        $this->notificationService->createNotification(
            userId: $userId,
            code: 'submission.status_changed',
            payload: [
                'submissionId' => $submissionId,
                'name' => $name,
                'status' => 'transferred',
                'placeId' => $placeId,
            ],
        );
    }

    private function notifyUserSubmissionRejected(string $userId, string $submissionId, string $name): void
    {
        $this->notificationService->createNotification(
            userId: $userId,
            code: 'submission.status_changed',
            payload: [
                'submissionId' => $submissionId,
                'name' => $name,
                'status' => 'rejected',
            ],
        );
    }

    private function format(array $submission): array
    {
        return [
            'id' => $submission['id'],
            'userId' => $submission['userId'],
            'name' => $submission['name'],
            'address' => $submission['address'],
            'latitude' => (float) $submission['latitude'],
            'longitude' => (float) $submission['longitude'],
            'mapLink' => $submission['mapLink'],
            'website' => $submission['website'],
            'rating' => $submission['rating'] !== null ? (int) $submission['rating'] : null,
            'comment' => $submission['comment'],
            'note' => $submission['note'],
            'status' => $submission['status'],
            'adminNote' => $submission['adminNote'],
            'targetPlaceId' => $submission['targetPlaceId'],
            'createdAt' => $submission['createdAt'],
            'updatedAt' => $submission['updatedAt'],
        ];
    }
}
