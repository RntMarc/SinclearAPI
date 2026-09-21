# Message Reaction Plan

## Overview
This document outlines the planned implementation for message reactions in Sinclear's chat system.

## Goals
- Allow users to react to messages with Unicode emoji
- Show reaction counts and which users reacted
- Support adding/removing reactions
- Real-time updates via Centrifugo

## Data Model

### Database Schema
```sql
CREATE TABLE MessageReaction (
  id VARCHAR(191) NOT NULL,
  messageId VARCHAR(191) NOT NULL,
  userId VARCHAR(191) NOT NULL,
  emoji VARCHAR(10) NOT NULL,
  createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_message_user_emoji (messageId, userId, emoji),
  CONSTRAINT fk_reaction_message FOREIGN KEY (messageId) REFERENCES Message(id) ON DELETE CASCADE,
  CONSTRAINT fk_reaction_user FOREIGN KEY (userId) REFERENCES User(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### API Endpoints

#### Add Reaction
```
POST /chat/messages/{messageId}/reactions
Body: { "emoji": "👍" }
Response: 201 Created
```

#### Remove Reaction
```
DELETE /chat/messages/{messageId}/reactions/{emoji}
Response: 204 No Content
```

#### Get Reactions for Message
```
GET /chat/messages/{messageId}/reactions
Response: 200 OK
{
  "data": {
    "👍": {
      "count": 3,
      "users": ["user1", "user2", "user3"],
      "me": true
    },
    "❤️": {
      "count": 1,
      "users": ["user4"],
      "me": false
    }
  }
}
```

## Centrifugo Events

### Reaction Added
```json
{
  "type": "reaction_added",
  "messageId": "msg_123",
  "userId": "user_456",
  "emoji": "👍",
  "seq": 789
}
```

### Reaction Removed
```json
{
  "type": "reaction_removed",
  "messageId": "msg_123",
  "userId": "user_456",
  "emoji": "👍",
  "seq": 790
}
```

## UI Design

### Reaction Display
- Show reactions below message content
- Group by emoji with count
- Highlight own reactions
- Tap to toggle own reaction

### Reaction Picker
- Long-press on message to open reaction picker
- Show frequently used emoji
- Support custom emoji (future)

## Implementation Phases

### Phase 1: Backend
1. Create MessageReaction table migration
2. Implement MessageReactionRepository
3. Add API endpoints to ChatController
4. Update openapi.yaml
5. Add Centrifugo event publishing

### Phase 2: Client
1. Add reaction models
2. Create ReactionService
3. Extend CentrifugoService for reaction events
4. Build reaction picker widget
5. Add reactions to message bubbles
6. Update chat list for reaction indicators

### Phase 3: Polish
1. Add reaction animations
2. Optimize performance for messages with many reactions
3. Add reaction search/filter (future)

## Technical Considerations

### Performance
- Index on (messageId, emoji) for fast counts
- Cache reaction counts in Message table for quick access
- Batch reaction updates for bulk operations

### Real-time
- Use existing Centrifugo chat channel
- Include reaction data in message events
- Optimistic UI updates with server confirmation

### Accessibility
- Screen reader labels for reactions
- Keyboard navigation for reaction picker
- High contrast mode support

## Future Enhancements
- Custom emoji support
- Reaction animations
- Reaction notifications
- Reaction analytics
- Animated emoji
