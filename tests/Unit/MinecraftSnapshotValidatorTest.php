<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\Minecraft\MinecraftSnapshotValidator;
use Sinclear\Api\Services\Minecraft\MinecraftValidationException;

final class MinecraftSnapshotValidatorTest extends TestCase
{
    private MinecraftSnapshotValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new MinecraftSnapshotValidator(
            maxPlayers: 200,
            maxStatsPerPlayer: 10000,
            futureToleranceSeconds: 300,
        );
    }

    public function testValidPayloadIsNormalized(): void
    {
        $result = $this->validator->validate($this->basePayload());

        self::assertSame('MedionSMP', $result['serverId']);
        self::assertSame('world', $result['world']);
        self::assertSame('866a12cc-4ea1-4e2b-96d1-c87094707c67', $result['snapshotId']);
        self::assertSame('2026-11-25 22:56:12.000', $result['collectedAt']);
        self::assertCount(1, $result['players']);
        self::assertSame('f6d584ff-9577-472b-9aa2-88b7878eac37', $result['players'][0]['uuid']);
        self::assertSame('Notch', $result['players'][0]['name']);
        self::assertSame(5023, $result['players'][0]['dataVersion']);
        self::assertCount(2, $result['players'][0]['stats']);
    }

    public function testUuidWithoutHyphensIsNormalized(): void
    {
        $payload = $this->basePayload();
        $payload['snapshotId'] = '866A12CC4EA14E2B96D1C87094707C67';
        $payload['players'][0]['uuid'] = 'F6D584FF9577472B9AA288B7878EAC37';

        $result = $this->validator->validate($payload);

        self::assertSame('866a12cc-4ea1-4e2b-96d1-c87094707c67', $result['snapshotId']);
        self::assertSame('f6d584ff-9577-472b-9aa2-88b7878eac37', $result['players'][0]['uuid']);
    }

    public function testUnsupportedSchemaVersion(): void
    {
        $payload = $this->basePayload();
        $payload['schemaVersion'] = 2;

        $this->expectException(MinecraftValidationException::class);
        $this->validator->validate($payload);
    }

    public function testMissingSchemaVersion(): void
    {
        $payload = $this->basePayload();
        unset($payload['schemaVersion']);

        try {
            $this->validator->validate($payload);
            self::fail('expected validation exception');
        } catch (MinecraftValidationException $e) {
            self::assertSame('unsupported_schema_version', $e->errorCode);
        }
    }

    public function testInvalidServerId(): void
    {
        $payload = $this->basePayload();
        $payload['serverId'] = 'bad server!';

        $details = $this->details($payload);

        self::assertSame('invalid_server_id', $details['serverId']);
    }

    public function testInvalidUuid(): void
    {
        $payload = $this->basePayload();
        $payload['players'][0]['uuid'] = 'not-a-uuid';

        self::assertSame('invalid_uuid', $this->details($payload)['players.0.uuid']);
    }

    public function testDuplicateUuid(): void
    {
        $payload = $this->basePayload();
        $payload['players'][] = $this->player(uuid: 'f6d584ff-9577-472b-9aa2-88b7878eac37');

        self::assertSame('duplicate_uuid', $this->details($payload)['players.1.uuid']);
    }

    public function testInvalidName(): void
    {
        $payload = $this->basePayload();
        $payload['players'][0]['name'] = 'bad name!';

        self::assertSame('invalid_name', $this->details($payload)['players.0.name']);
    }

    public function testDataVersionMustBeInt(): void
    {
        $payload = $this->basePayload();
        $payload['players'][0]['dataVersion'] = '5023';

        self::assertSame('invalid_type', $this->details($payload)['players.0.dataVersion']);
    }

    public function testNegativeAndFloatStatValuesAreRejected(): void
    {
        $payload = $this->basePayload();
        $payload['players'][0]['stats']['minecraft:custom']['minecraft:play_time'] = -1;

        self::assertSame('invalid_stat_value', $this->details($payload)['players.0.stats']);

        $payload = $this->basePayload();
        $payload['players'][0]['stats']['minecraft:custom']['minecraft:play_time'] = 1.5;

        self::assertSame('invalid_stat_value', $this->details($payload)['players.0.stats']);
    }

    public function testInvalidStatKeyIsRejected(): void
    {
        $payload = $this->basePayload();
        $payload['players'][0]['stats']['UPPERCASE:Key'] = ['minecraft:play_time' => 1];

        self::assertSame('invalid_stat_key', $this->details($payload)['players.0.stats']);
    }

    public function testTooManyPlayers(): void
    {
        $validator = new MinecraftSnapshotValidator(maxPlayers: 1);
        $payload = $this->basePayload();
        $payload['players'][] = $this->player(uuid: '00000000-0000-0000-0000-000000000001');

        try {
            $validator->validate($payload);
            self::fail('expected validation exception');
        } catch (MinecraftValidationException $e) {
            self::assertContains(['field' => 'players', 'error' => 'too_many_players'], $e->details);
        }
    }

    public function testTooManyStats(): void
    {
        $validator = new MinecraftSnapshotValidator(maxStatsPerPlayer: 1);
        $payload = $this->basePayload();

        try {
            $validator->validate($payload);
            self::fail('expected validation exception');
        } catch (MinecraftValidationException $e) {
            self::assertContains(['field' => 'players.0.stats', 'error' => 'too_many_stats'], $e->details);
        }
    }

    public function testEmptyPlayers(): void
    {
        $payload = $this->basePayload();
        $payload['players'] = [];

        self::assertSame('too_few_players', $this->details($payload)['players']);
    }

    public function testMissingPlayers(): void
    {
        $payload = $this->basePayload();
        unset($payload['players']);

        self::assertSame('required', $this->details($payload)['players']);
    }

    public function testFutureCollectedAt(): void
    {
        $payload = $this->basePayload();
        $payload['collectedAt'] = '2099-01-01T00:00:00Z';

        self::assertSame('future_timestamp', $this->details($payload)['collectedAt']);
    }

    public function testInvalidCollectedAt(): void
    {
        $payload = $this->basePayload();
        $payload['collectedAt'] = '2026-11-25T22:56:12';

        self::assertSame('invalid_timestamp', $this->details($payload)['collectedAt']);
    }

    public function testEmptyStatsIsAllowed(): void
    {
        $payload = $this->basePayload();
        $payload['players'][0]['stats'] = [];

        $result = $this->validator->validate($payload);

        self::assertSame([], $result['players'][0]['stats']);
    }

    public function testPlayerCountAndUnknownFieldsAreIgnored(): void
    {
        $payload = $this->basePayload();
        $payload['playerCount'] = 999;
        $payload['unknownField'] = 'ignored';

        $result = $this->validator->validate($payload);

        self::assertSame('MedionSMP', $result['serverId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'schemaVersion' => 1,
            'serverId' => 'MedionSMP',
            'world' => 'world',
            'snapshotId' => '866a12cc-4ea1-4e2b-96d1-c87094707c67',
            'collectedAt' => '2026-11-25T22:56:12Z',
            'playerCount' => 1,
            'players' => [
                $this->player(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function player(
        string $uuid = 'f6d584ff-9577-472b-9aa2-88b7878eac37',
    ): array {
        return [
            'uuid' => $uuid,
            'name' => 'Notch',
            'dataVersion' => 5023,
            'stats' => [
                'minecraft:custom' => [
                    'minecraft:play_time' => 14288,
                    'minecraft:walk_one_cm' => 7845,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function details(array $payload): array
    {
        try {
            $this->validator->validate($payload);
        } catch (MinecraftValidationException $e) {
            $map = [];
            foreach ($e->details as $detail) {
                $map[$detail['field']] = $detail['error'];
            }
            return $map;
        }

        self::fail('expected validation exception');
    }
}
