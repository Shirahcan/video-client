<?php

namespace Shirahcan\VideoClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Shirahcan\VideoClient\Laravel\Events\CallbackReceiver;

/**
 * POST {uri} from video-service (VideoKit::callbackRoute): its events for this product's rooms.
 * The product handles them by listening for VideoEventReceived.
 */
class CallbackController extends Controller
{
    /** How long a delivered event id is remembered, so a redelivery is not run twice. */
    private const SEEN_FOR_SECONDS = 7 * 86400;

    public function __invoke(Request $request): JsonResponse
    {
        $receiver = new CallbackReceiver(
            (string) config('video-client.callback_secret'),
            fn (string $id) => Cache::add('video-client:event:'.$id, 1, self::SEEN_FOR_SECONDS),
            fn (string $id) => Cache::forget('video-client:event:'.$id),
            fn (object $e) => event($e),
            fn (\Throwable $e) => report($e),
        );

        [$status, $message] = $receiver->receive(
            $request->getContent(),
            $request->header('X-Video-Signature'),
            $request->header('X-Video-Timestamp'),
        );

        return response()->json(['success' => $status === 200, 'message' => $message], $status);
    }
}
