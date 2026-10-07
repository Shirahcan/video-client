<?php

namespace Shirahcan\VideoClient\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
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
}
