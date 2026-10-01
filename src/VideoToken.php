<?php

namespace Shirahcan\VideoClient;

/** A meeting token and the ONLY form of the room URL a product may hand to a browser. */
final class VideoToken
{
    public function __construct(
        public readonly string $token,
        /** The room URL WITH `?t=` already on it. Embed this; never strip the token. */
        public readonly string $url,
        public readonly string $expiresAt,
        /** The call-state contract at the moment of minting (V16); null from older services. */
        public readonly ?VideoCallState $call = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['token'] ?? ''),
            (string) ($data['url'] ?? ''),
            (string) ($data['expires_at'] ?? ''),
            is_array($data['call'] ?? null) ? VideoCallState::fromArray($data['call']) : null,
        );
    }
}
