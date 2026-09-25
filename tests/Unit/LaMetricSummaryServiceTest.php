<?php

declare(strict_types=1);

namespace Sinclear\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sinclear\Api\Services\LaMetricSummaryService;

class LaMetricSummaryServiceTest extends TestCase
{
    public function testEmptyCountsReturnsAllesGelesen(): void
    {
        self::assertSame('Alles gelesen', LaMetricSummaryService::buildText([]));
    }

    public function testNonPositiveCountsAreIgnored(): void
    {
        self::assertSame('Alles gelesen', LaMetricSummaryService::buildText(['direct_message' => 0]));
    }

    public function testSingleChatUsesSingular(): void
    {
        self::assertSame('1 neuer Chat', LaMetricSummaryService::buildText(['direct_message' => 1]));
    }

    public function testExampleSentenceFromSpec(): void
    {
        self::assertSame(
            '10 neue Chats, 3 neue Foren-Beiträge und 1 neue Reise',
            LaMetricSummaryService::buildText([
                'direct_message' => 10,
                'forum_reply' => 2,
                'forum_post' => 1,
                'trip_user_added' => 1,
            ]),
        );
    }

    public function testTwoCategoriesJoinedWithUnd(): void
    {
        self::assertSame(
            '2 neue Chats und 1 neues Event',
            LaMetricSummaryService::buildText([
                'direct_message' => 2,
                'trip_event_added' => 1,
            ]),
        );
    }

    public function testUnknownTypeFallsBackToOtherBucket(): void
    {
        self::assertSame('3 neue Benachrichtigungen', LaMetricSummaryService::buildText(['brand_new_type' => 3]));
    }

    public function testBucketsKeepTheirOrder(): void
    {
        self::assertSame(
            '1 neuer Chat, 2 neue Foren-Beiträge, 3 neue Reisen, 4 neue Events und 5 neue Stories',
            LaMetricSummaryService::buildText([
                'story_post' => 5,
                'trip_event_added' => 4,
                'trip_info_changed' => 3,
                'forum_upvote' => 2,
                'direct_message' => 1,
            ]),
        );
    }

    public function testForumTypesAreAggregated(): void
    {
        self::assertSame(
            '4 neue Foren-Beiträge',
            LaMetricSummaryService::buildText([
                'forum_reply' => 1,
                'forum_comment' => 1,
                'forum_post' => 1,
                'forum_upvote' => 1,
            ]),
        );
    }
}
