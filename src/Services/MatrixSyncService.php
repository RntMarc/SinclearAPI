<?php

declare(strict_types=1);

namespace Sinclear\Api\Services;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Sinclear\Api\Application\Settings;
use Sinclear\Api\Repository\MatrixAccountRepository;
use Sinclear\Api\Repository\MatrixSyncOperationRepository;

/**
 * Fachlogik des Matrix-Syncs (Application Service).
 *
 * Die Datenbank ist die Wahrheit darüber, was gewollt ist (MatrixAccount,
 * MatrixSyncOperation-Outbox) und was bereits in Matrix umgesetzt wurde. Der
 * Cron-Task ist nur der Motor, der die Differenz abarbeitet. Dadurch ist der
 * Sync selbstheilend: bleibt der Homeserver offline, bleiben Operationen
 * `pending` und werden beim nächsten Lauf erneut versucht.
 */
final class MatrixSyncService
{
    public const string TYPE_CREATE = 'create';
    public const string TYPE_DISPLAYNAME = 'displayname';

    private const int BACKOFF_BASE_SECONDS = 300;   // 5 Minuten
    private const int BACKOFF_MAX_SECONDS = 3600;   // 60 Minuten
    private const int BACKOFF_JITTER_SECONDS = 30;  // 0–30 s Jitter

    public function __construct(
        private MatrixAccountRepository $accountRepo,
        private MatrixSyncOperationRepository $operationRepo,
        private MatrixClient $client,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {}

    /**
     * Reinigt eine Operation für einen erneuten Versuch (Admin-Aktion).
     */
    public function retryOperation(string $operationId): bool
    {
        return $this->operationRepo->resetForRetry($operationId);
    }

    /**
     * Reiht die Account-Erstellung für einen Nutzer ein (idempotent).
     *
     * Legt bei Bedarf die MatrixAccount-Zeile an (Passwort generieren + verschlüsseln)
     * und erzeugt genau eine pending `create`-Operation.
     */
    public function enqueueCreate(string $userId): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $account = $this->accountRepo->findByUserId($userId);
        if ($account === null) {
            $this->accountRepo->create(
                $userId,
                $this->localpartFor($userId),
                $this->encryptPassword($this->generatePassword()),
            );
        }

        if (!$this->operationRepo->hasPending($userId, self::TYPE_CREATE)) {
            $this->operationRepo->create($userId, self::TYPE_CREATE, null);
        }
    }

    /**
     * Reiht die Anzeigename-Synchronisierung ein (idempotent).
     *
     * Bei bereits pending `displayname`-Operation wird nur der Payload
     * (der neueste Name) aktualisiert.
     */
    public function enqueueDisplayName(string $userId, string $displayName): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if ($this->operationRepo->hasPending($userId, self::TYPE_DISPLAYNAME)) {
            $this->operationRepo->updatePayloadForPending($userId, self::TYPE_DISPLAYNAME, ['displayName' => $displayName]);
        } else {
            $this->operationRepo->create($userId, self::TYPE_DISPLAYNAME, ['displayName' => $displayName]);
        }
    }

    /**
     * Phase A — Reconciliation: stellt sicher, dass Soll ≠ Ist als pending
     * Operation abgebildet wird (selbstheilend, Backfill).
     */
    public function reconcile(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        // (1) Fehlende Accounts / ausstehendes create
        $missing = $this->accountRepo->findUserIdsMissingAccountOrMatrixId();
        foreach ($missing as $userId) {
            if ($this->operationRepo->hasPending($userId, self::TYPE_CREATE)) {
                continue;
            }
            $account = $this->accountRepo->findByUserId($userId);
            if ($account === null) {
                $this->accountRepo->create(
                    $userId,
                    $this->localpartFor($userId),
                    $this->encryptPassword($this->generatePassword()),
                );
            }
            $this->operationRepo->create($userId, self::TYPE_CREATE, null);
        }

        // (2) Drift beim Anzeigenamen
        $drift = $this->accountRepo->findDisplayNameDrift();
        foreach ($drift as $row) {
            $userId = $row['userId'];
            if ($this->operationRepo->hasPending($userId, self::TYPE_DISPLAYNAME)) {
                continue;
            }
            $this->operationRepo->create($userId, self::TYPE_DISPLAYNAME, ['displayName' => $row['displayName']]);
        }
    }

    /**
     * Phase B — fällige Operationen verarbeiten (Retry/Backoff).
     */
    public function processDueOperations(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $batchSize = max(1, (int) ($this->settings->matrix['sync_batch_size'] ?? 50));
        $operations = $this->operationRepo->findDue($batchSize);

        foreach ($operations as $operation) {
            try {
                $this->processOne($operation);
            } catch (MatrixClientException $e) {
                $this->handleFailure($operation, $e);
            } catch (\Throwable $e) {
                $this->handleFailure($operation, MatrixClientException::transient($e->getMessage()));
            }
        }
    }

    /**
     * Backoff als reine Funktion: min(5min × 2^previousAttempts, 60min).
     * previousAttempts = Anzahl bisheriger Fehlversuche (0 = erster Fehlversuch).
     */
    public static function calculateBackoffSeconds(int $previousAttempts): int
    {
        $attempts = max(0, $previousAttempts);
        return min(self::BACKOFF_BASE_SECONDS * (2 ** $attempts), self::BACKOFF_MAX_SECONDS);
    }

    /** @param array<string, mixed> $operation */
    private function processOne(array $operation): void
    {
        $account = $this->accountRepo->findByUserId($operation['userId']);
        if ($account === null) {
            $this->operationRepo->markFailed($operation['id'], 'MatrixAccount row missing');
            return;
        }

        if ($operation['type'] === self::TYPE_CREATE) {
            $this->processCreate($operation, $account);
        } else {
            $this->processDisplayName($operation, $account);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $account */
    private function processCreate(array $operation, array $account): void
    {
        $password = $this->decryptPassword($account['passwordEncrypted']);
        $matrixUserId = $this->client->registerAs($account['localpart'], $password);

        $this->accountRepo->setMatrixUserId($account['userId'], $matrixUserId);
        $this->operationRepo->markDone($operation['id']);

        // Initialen Anzeigenamen einreihen (aktueller Name aus User-Tabelle)
        $displayName = $this->accountRepo->getDisplayName($account['userId']);
        if ($displayName !== null && $displayName !== '') {
            $this->enqueueDisplayName($account['userId'], $displayName);
        }
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $account */
    private function processDisplayName(array $operation, array $account): void
    {
        $matrixUserId = $account['matrixUserId'] ?? null;
        if ($matrixUserId === null || $matrixUserId === '') {
            // create noch nicht abgeschlossen — pending lassen, nächster Lauf
            $this->logger->info('Matrix sync: displayname op skipped, create pending', [
                'userId' => $account['userId'],
            ]);
            return;
        }

        $payload = json_decode((string) ($operation['payload'] ?? '{}'), true);
        $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;
        if ($displayName === null || $displayName === '') {
            $displayName = $this->accountRepo->getDisplayName($account['userId']);
        }
        if ($displayName === null || $displayName === '') {
            $this->operationRepo->markFailed($operation['id'], 'empty display name');
            return;
        }

        $this->client->setDisplayName($matrixUserId, (string) $displayName);
        $this->accountRepo->setDisplayNameSynced($account['userId'], (string) $displayName);
        $this->operationRepo->markDone($operation['id']);
    }

    /** @param array<string, mixed> $operation */
    private function handleFailure(array $operation, MatrixClientException $e): void
    {
        if ($e->isPermanent()) {
            $this->operationRepo->markFailed($operation['id'], $e->getMessage());
            $this->logger->warning('Matrix sync: permanent failure', [
                'operationId' => $operation['id'],
                'type' => $operation['type'],
                'errcode' => $e->errcode(),
                'status' => $e->statusCode(),
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $previousAttempts = (int) ($operation['attempts'] ?? 0);
        $backoffSeconds = self::calculateBackoffSeconds($previousAttempts);
        $jitter = random_int(0, self::BACKOFF_JITTER_SECONDS);
        $nextAttemptAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . ($backoffSeconds + $jitter) . ' seconds')
            ->format('Y-m-d H:i:s.v');

        $this->operationRepo->recordTransientFailure($operation['id'], $e->getMessage(), $nextAttemptAt);
        $this->logger->info('Matrix sync: transient failure, retry scheduled', [
            'operationId' => $operation['id'],
            'type' => $operation['type'],
            'backoffSeconds' => $backoffSeconds + $jitter,
        ]);
    }

    private function isConfigured(): bool
    {
        $m = $this->settings->matrix;
        return !empty($m['homeserver_url'])
            && !empty($m['as_token'])
            && !empty($m['server_name'])
            && $this->passwordKeyBytes() !== null;
    }

    private function localpartFor(string $userId): string
    {
        $prefix = (string) ($this->settings->matrix['namespace_prefix'] ?? 'sb_');
        return $prefix . str_replace('-', '', $userId);
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function passwordKeyBytes(): ?string
    {
        $hex = (string) ($this->settings->matrix['password_key'] ?? '');
        if ($hex === '') {
            return null;
        }
        $key = @hex2bin($hex);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }
        return $key;
    }

    private function encryptPassword(string $password): string
    {
        $key = $this->passwordKeyBytes();
        if ($key === null) {
            throw new \RuntimeException('MATRIX_PASSWORD_KEY missing or invalid (32-byte hex required)');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($password, $nonce, $key);
        return base64_encode($nonce) . ':' . base64_encode($ciphertext);
    }

    private function decryptPassword(string $encrypted): string
    {
        $key = $this->passwordKeyBytes();
        if ($key === null) {
            throw new \RuntimeException('MATRIX_PASSWORD_KEY missing or invalid (32-byte hex required)');
        }
        $parts = explode(':', $encrypted, 2);
        if (count($parts) !== 2) {
            throw new \RuntimeException('Invalid encrypted matrix password format');
        }
        $nonce = base64_decode($parts[0], true);
        $ciphertext = base64_decode($parts[1], true);
        if ($nonce === false || $ciphertext === false) {
            throw new \RuntimeException('Invalid encrypted matrix password encoding');
        }
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt matrix password');
        }
        return $plaintext;
    }
}
