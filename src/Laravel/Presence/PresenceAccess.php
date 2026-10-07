<?php

namespace Shirahcan\VideoClient\Laravel\Presence;

use Illuminate\Http\Request;

/**
 * The product's answer to "who is this, in which call": signed in as a participant of the
 * meeting, or holding that meeting's emailed link. Null = nobody the product recognises, answered
 * 404 by the kit.
 */
interface PresenceAccess
{
    public function participant(Request $request, string $meeting): ?PresenceParticipant;
}
