<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Security\Policy\MinecraftIngestPolicy;

final class MinecraftIngestPolicyTest extends TestCase
{
    public function testCanIngestOnlyWhenAuthenticated(): void
    {
        $policy = new MinecraftIngestPolicy();

        self::assertTrue($policy->canIngest(true));
        self::assertFalse($policy->canIngest(false));
    }
}
