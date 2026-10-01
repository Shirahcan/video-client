# shirahcan/video-client

Thin client for the estate's `video-service`. Speaks to it over loopback and holds **no
Daily key of its own**.

```php
use Shirahcan\VideoClient\VideoClient;

$room  = app(VideoClient::class)->createRoom('meeting-123', $start, $end, ['transcription' => true]);
$token = app(VideoClient::class)->token($room->name, $user->id, $user->name, isOwner: true);
// $token->url is the ONLY joinable URL: it carries the token.
```

Config (three values, nothing else):

```dotenv
VIDEO_SERVICE_URL=http://127.0.0.1:8008
VIDEO_SERVICE_TRUST_KEY=<issued by `php artisan video:issue-key <product>` on the service>
VIDEO_SERVICE_TIMEOUT=15
# Only for the callback receiver (printed once by `video:set-callback`):
VIDEO_SERVICE_CALLBACK_SECRET=
```

One exception per remedy: `VideoServiceUnavailable` (retry), `RoomStranded` (repair the
room), `VideoOverBudget` (a human raises the cap), `RoomNotFound` (adopt it, or a caller
bug), `VideoRequestRejected` (fix the request).

Tests: bind `FakeVideoClient` to `VideoClient`. Callbacks: `WebhookSignature::verify()`.
