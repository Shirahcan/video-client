<?php

namespace Shirahcan\VideoClient\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Shirahcan\VideoClient\Laravel\Http\CallbackController;
use Shirahcan\VideoClient\Laravel\Http\PresenceController;

/**
 * Entry points of video-client's product-side kit, so every product wires calls the same way.
 */
final class VideoKit
{
    /**
     * Presence from the call page (video-embed's CallFrame `presence`), relayed to video-service.
     * Call it inside whichever route group gives the product's PresenceAccess what it needs (a
     * signed-in guard, or none for an emailed link the access class checks itself):
     *
     *     VideoKit::presenceRoute('/v1/calendar/meetings', 'calendar.meetings.presence');
     */
    public static function presenceRoute(string $prefix, string $name = 'video.presence'): Route
    {
        return Router::post(rtrim($prefix, '/').'/{meeting}/presence', PresenceController::class)->name($name);
    }

    /**
     * Where video-service delivers this product's call events (register the full URL on the
     * service with `video:set-callback <product> <url>`). Signature-checked with
     * VIDEO_SERVICE_CALLBACK_SECRET; each event reaches the product once as VideoEventReceived.
     * Mount it OUTSIDE any auth middleware: the signature is the authentication.
     *
     *     VideoKit::callbackRoute('v1/webhooks/video-service', 'webhooks.video-service');
     */
    public static function callbackRoute(string $uri = 'webhooks/video-service', string $name = 'video.callback'): Route
    {
        return Router::post(ltrim($uri, '/'), CallbackController::class)->name($name);
    }
}
