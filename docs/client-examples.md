# Client-Beispiele

## OTP-Login

```bash
# 1. OTP anfordern
curl -X POST http://localhost:8080/api/v1/auth/otp/request \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com"}'

# 2. OTP verifizieren
curl -X POST http://localhost:8080/api/v1/auth/otp/verify \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","code":"123456"}'
```

Response:

```json
{
  "accessToken": "eyJ...",
  "refreshToken": "a1b2c3...",
  "expiresIn": 900,
  "user": { "id": "...", "email": "...", "displayName": "..." }
}
```

## Authentifizierte Anfrage

```bash
curl http://localhost:8080/api/v1/auth/me \
  -H "Authorization: Bearer eyJ..."
```

## Token erneuern

```bash
curl -X POST http://localhost:8080/api/v1/auth/refresh \
  -H "Content-Type: application/json" \
  -d '{"refreshToken":"a1b2c3..."}'
```

## Paginierte Liste

```bash
curl "http://localhost:8080/api/v1/events?page=1&limit=25&sort=startAt&order=asc" \
  -H "Authorization: Bearer eyJ..."
```

## Poll abstimmen

```bash
curl -X POST http://localhost:8080/api/v1/polls/{id}/votes \
  -H "Authorization: Bearer eyJ..." \
  -H "Content-Type: application/json" \
  -d '{"answers":[{"questionId":"q1","optionId":"o1"}]}'
```

## Chat-Nachricht senden

```bash
curl -X POST http://localhost:8080/api/v1/chat/messages \
  -H "Authorization: Bearer eyJ..." \
  -H "Content-Type: application/json" \
  -d '{"chat_id":"room-uuid","chat_type":"group","body":"Hallo!"}'
```

## SSE Event-Stream

```bash
curl -N http://localhost:8080/api/v1/chat/events/stream \
  -H "Authorization: Bearer eyJ..."
```
