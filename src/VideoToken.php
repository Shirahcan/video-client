<?php

namespace Shirahcan\VideoClient;

/** A meeting token and the ONLY form of the room URL a product may hand to a browser (bare only when `knock`). */
final class VideoToken
{
    public function __construct(
        public readonly string $token,
        /** The room URL WITH `?t=` already on it. Embed this; never strip the token. */
        public readonly string $url,
        public readonly string $expiresAt,
        /** The call-state contract at the moment of minting (V16); null from older services. */
        public readonly ?VideoCallState $call = null,
        /**
         * A host removed this participant from the call: there is NO token, and `url` is the bare
         * room, which puts them in Daily's lobby to ask to join (owner 2026-11-27).
         */
        public readonly bool $knock = false,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['token'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['expires_at'] ?? ''),
            is_array($data['call'] ?? null) ? VideoCallState::fromArray($data['call']) : null,
            (bool) ($data['knock'] ?? false),
        );
    }
}
