<?php

declare(strict_types=1);

use Sinclear\Api\Dto\SensitiveFields;
use Sinclear\Api\Dto\UserDto;
use Sinclear\Api\Security\Policy\AdminOnlyPolicy;
use Sinclear\Api\Security\Policy\AuthenticatedReadPolicy;
use Sinclear\Api\Security\Policy\ChatMessagePolicy;
use Sinclear\Api\Security\Policy\CreatorPolicy;
use Sinclear\Api\Security\Policy\DirectChatPolicy;

use Sinclear\Api\Security\Policy\OwnerPolicy;
use Sinclear\Api\Security\Policy\SnakeOwnerPolicy;
use Sinclear\Api\Security\Policy\PolicyInterface;
use Sinclear\Api\Security\Policy\UserListPolicy;

/**
 * Registry mapping REST routes to database tables and authorization policies.
 *
 * @return list<array{route: string, table: string, pk?: string, policy: class-string<PolicyInterface>, mapper?: callable}>
 */
return [
    ['route' => 'users', 'table' => 'User', 'policy' => UserListPolicy::class, 'mapper' => [UserDto::class, 'fromRow']],
    ['route' => 'user-preferences', 'table' => 'UserPreferences', 'policy' => OwnerPolicy::class],
    ['route' => 'contact-info', 'table' => 'ContactInfo', 'policy' => OwnerPolicy::class, 'pk' => 'userId'],

    ['route' => 'social-info', 'table' => 'SocialInfo', 'policy' => OwnerPolicy::class, 'pk' => 'userId'],

    ['route' => 'close-friends', 'table' => 'CloseFriend', 'policy' => OwnerPolicy::class],
    // Removed: events — handled by custom EventController
    ['route' => 'event-permissions', 'table' => 'EventPermission', 'policy' => OwnerPolicy::class],
    ['route' => 'event-relations', 'table' => 'EventRelation', 'policy' => OwnerPolicy::class],
    ['route' => 'forums', 'table' => 'Forum', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'forum-members', 'table' => 'ForumMember', 'policy' => OwnerPolicy::class],
    ['route' => 'posts', 'table' => 'FeedPosts', 'policy' => CreatorPolicy::class],
    ['route' => 'post-votes', 'table' => 'FeedPostVote', 'policy' => OwnerPolicy::class],
    // Removed: polls — handled by custom PollController
    ['route' => 'poll-questions', 'table' => 'PollQuestion', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'poll-options', 'table' => 'PollOption', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'poll-invites', 'table' => 'PollInvite', 'policy' => OwnerPolicy::class],
    ['route' => 'poll-votes', 'table' => 'PollVote', 'policy' => OwnerPolicy::class],
    ['route' => 'recipes', 'table' => 'Recipe', 'policy' => CreatorPolicy::class],
    ['route' => 'recipe-ingredients', 'table' => 'RecipeIngredient', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'recipe-steps', 'table' => 'RecipeStep', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'recipe-reviews', 'table' => 'RecipeReview', 'policy' => OwnerPolicy::class],
    ['route' => 'recipes/reviews', 'table' => 'RecipeReview', 'policy' => OwnerPolicy::class],
    ['route' => 'recipe-bookmarks', 'table' => 'RecipeBookmark', 'policy' => OwnerPolicy::class],
    ['route' => 'recipes/bookmarks', 'table' => 'RecipeBookmark', 'policy' => OwnerPolicy::class],
    ['route' => 'media-items', 'table' => 'MediaItem', 'policy' => CreatorPolicy::class],
    ['route' => 'media-reviews', 'table' => 'MediaReview', 'policy' => OwnerPolicy::class],
    ['route' => 'series-episodes', 'table' => 'SeriesEpisode', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'episode-reviews', 'table' => 'EpisodeReview', 'policy' => OwnerPolicy::class],
    ['route' => 'album-tracks', 'table' => 'AlbumTrack', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'discover-places', 'table' => 'DiscoverPlace', 'policy' => CreatorPolicy::class],
    ['route' => 'discover/places', 'table' => 'DiscoverPlace', 'policy' => CreatorPolicy::class],
    ['route' => 'discover-gastronomy', 'table' => 'DiscoverGastronomy', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'discover-reviews', 'table' => 'DiscoverReview', 'policy' => OwnerPolicy::class],
    ['route' => 'discover/reviews', 'table' => 'DiscoverReview', 'policy' => OwnerPolicy::class],
    ['route' => 'discover-bookmarks', 'table' => 'DiscoverBookmark', 'policy' => OwnerPolicy::class],
    ['route' => 'discover/bookmarks', 'table' => 'DiscoverBookmark', 'policy' => OwnerPolicy::class],
    ['route' => 'feedback-suggestions', 'table' => 'FeedbackSuggestion', 'policy' => OwnerPolicy::class],
    ['route' => 'feedback/suggestions', 'table' => 'FeedbackSuggestion', 'policy' => OwnerPolicy::class],
    ['route' => 'feedback-votes', 'table' => 'FeedbackVote', 'policy' => OwnerPolicy::class],
    ['route' => 'feedback/votes', 'table' => 'FeedbackVote', 'policy' => OwnerPolicy::class],
    ['route' => 'news-articles', 'table' => 'NewsArticle', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'news-upvotes', 'table' => 'NewsUpvote', 'policy' => OwnerPolicy::class],
    // Removed: rss-sources — handled by custom NewsController
    // Removed: news-articles — handled by custom NewsController
    // Removed: news-upvotes — handled by custom NewsController
    ['route' => 'subscriptions', 'table' => 'Subscription', 'policy' => AdminOnlyPolicy::class],
    ['route' => 'subscription-relations', 'table' => 'SubscriptionRelation', 'policy' => OwnerPolicy::class],
    ['route' => 'office-documents', 'table' => 'OfficeDocument', 'policy' => CreatorPolicy::class],
    ['route' => 'office-versions', 'table' => 'OfficeVersion', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'office-collaborators', 'table' => 'OfficeCollaborator', 'policy' => OwnerPolicy::class],
    ['route' => 'notifications', 'table' => 'Notification', 'policy' => OwnerPolicy::class],
    ['route' => 'push-subscriptions', 'table' => 'PushSubscription', 'policy' => OwnerPolicy::class],
    ['route' => 'push/subscribe', 'table' => 'PushSubscription', 'policy' => OwnerPolicy::class],
    ['route' => 'changelog', 'table' => 'ChangelogEntry', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'travel-trips', 'table' => 'TravelTrip', 'policy' => AdminOnlyPolicy::class, 'pk' => 'ID'],
    ['route' => 'travel/trips', 'table' => 'TravelTrip', 'policy' => AdminOnlyPolicy::class, 'pk' => 'ID'],
    // Removed: travel-events, travel/events — handled by custom TravelController
    ['route' => 'travel-accommodations', 'table' => 'TravelAccommodation', 'policy' => AdminOnlyPolicy::class, 'pk' => 'ID'],
    ['route' => 'travel/accommodations', 'table' => 'TravelAccommodation', 'policy' => AdminOnlyPolicy::class, 'pk' => 'ID'],
    ['route' => 'travel-relations', 'table' => 'TravelRelation', 'policy' => AdminOnlyPolicy::class, 'pk' => 'ID'],
    ['route' => 'travel-event-tickets', 'table' => 'TravelEventTicket', 'policy' => AdminOnlyPolicy::class, 'pk' => 'ID'],
    ['route' => 'chat-rooms', 'table' => 'ChatRooms', 'policy' => AuthenticatedReadPolicy::class],
    ['route' => 'chat-room-members', 'table' => 'ChatRoomMembers', 'policy' => SnakeOwnerPolicy::class, 'pk' => 'chat_room_id'],
    ['route' => 'chat-messages', 'table' => 'ChatMessages', 'policy' => ChatMessagePolicy::class],
    ['route' => 'direct-chats', 'table' => 'DirectChat', 'policy' => DirectChatPolicy::class],
    ['route' => 'chat-read-receipts', 'table' => 'ChatReadReceipt', 'policy' => OwnerPolicy::class],
    ['route' => 'user-presence', 'table' => 'UserPresence', 'policy' => OwnerPolicy::class, 'pk' => 'userId'],
    ['route' => 'user-devices', 'table' => 'UserDevice', 'policy' => OwnerPolicy::class],
    ['route' => 'sse-events', 'table' => 'SseEvent', 'policy' => OwnerPolicy::class],
    ['route' => 'passkeys', 'table' => 'Passkey', 'policy' => OwnerPolicy::class, 'mapper' => static fn (array $r): array => SensitiveFields::strip($r, ['publicKey'])],
];
