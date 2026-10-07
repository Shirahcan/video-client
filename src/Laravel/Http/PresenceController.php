<?php

namespace Shirahcan\VideoClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Shirahcan\VideoClient\Laravel\Presence\PresenceAccess;
use Shirahcan\VideoClient\Laravel\Presence\RelayPresence;

/**
 * POST {prefix}/{meeting}/presence { kind: join|heartbeat|leave }, from video-embed's call frame,
 * the same in every product (VideoKit::presenceRoutes). Who it is comes from the product's
 * PresenceAccess; anyone else is 404.
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, string $meeting, RelayPresence $relay): JsonResponse
    {
        $class = config('video-client.presence.access');
        if (! is_string($class) || $class === '') {
            throw new RuntimeException('video-client: set video-client.presence.access to the product\'s PresenceAccess.');
        }
        $access = app($class);
        if (! $access instanceof PresenceAccess) {
            throw new RuntimeException("video-client: {$class} is not a PresenceAccess.");
        }

        $data = $request->validate(['kind' => ['required', 'in:join,heartbeat,leave']]);

        $who = $access->participant($request, $meeting);
        if ($who === null) {
            return response()->json(['message' => 'Meeting not found.'], 404);
        }

        $relay->relay($who, $data['kind']);

        return response()->json(['data' => ['recorded' => true]]);
    }
}
