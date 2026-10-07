<?php

namespace Shirahcan\VideoClient;

/**
 * A room as the service reports it. ⚠ No URL: a joinable URL only ever comes from
 * {@see VideoServiceClient::token()}, beside its token.
 */
final class VideoRoom
{
    public function __construct(
        public readonly string $name,
        public readonly string $externalRef,
        public readonly string $domain,
        public readonly string $status,
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        public readonly ?string $expiresAt = null,
        public readonly bool $adopted = false,
        /** Set once a host ended the call; the room admits nobody again. */
        public readonly ?string $endedAt = null,
    ) {}

    /**
     * The room's address WITHOUT a token, for the product's own records only (a stored
     * reference, a log line). Built here once, so no product builds it again. ⚠ Never give it to
     * a browser: a private room answers it with "you are not allowed to join". Embed
     * {@see VideoToken::$url}.
     */
    public function address(): string
    {
        return 'https://'.$this->domain.'.daily.co/'.$this->name;
    }

    public function isEnded(): bool
    {
        return $this->endedAt !== null;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            externalRef: (string) ($data['external_ref'] ?? ''),
            domain: (string) ($data['domain'] ?? ''),
            status: (string) ($data['status'] ?? ''),
            startsAt: $data['starts_at'] ?? null,
            endsAt: $data['ends_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            adopted: (bool) ($data['adopted'] ?? false),
            endedAt: $data['ended_at'] ?? null,
        );
    }
}
