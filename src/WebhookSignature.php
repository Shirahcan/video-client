<?php

namespace Shirahcan\VideoClient;

/**
 * Verifies a callback from video-service: headers `X-Video-Signature` (base64) and
 * `X-Video-Timestamp`, base64 HMAC-SHA256 over `"{timestamp}.{rawBody}"` with the
 * base64-decoded callback secret. The same scheme Daily uses for its own webhooks.
 */
final class WebhookSignature
{
    /** A callback older than this is refused (replay). */
    public const TOLERANCE_SECONDS = 86400;

    public static function verify(string $rawBody, ?string $signature, ?string $timestamp, string $base64Secret, ?int $now = null): bool
    {
        if ($signature === null || $signature === '' || $timestamp === null || ! ctype_digit($timestamp) || $base64Secret === '') {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $secret = base64_decode($base64Secret, true);
        if ($secret === false || $secret === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret, true)), $signature);
    }
}
