<?php

namespace Shirahcan\VideoClient;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Shirahcan\VideoClient\Exceptions\RoomNotFound;
use Shirahcan\VideoClient\Exceptions\RoomStranded;
use Shirahcan\VideoClient\Exceptions\VideoOverBudget;
use Shirahcan\VideoClient\Exceptions\VideoRequestRejected;
use Shirahcan\VideoClient\Exceptions\VideoServiceException;
use Shirahcan\VideoClient\Exceptions\VideoServiceUnavailable;

/**
 * Talks to video-service over loopback.
 *
 * ⚠ IT HOLDS NO DAILY KEY, AND MUST NEVER LEARN HOW TO. A client that carried its own
 * key "as a fallback" would recreate the three separate Daily integrations this
 * programme exists to end. If the service is unreachable, that is an outage to report
 * ({@see VideoServiceUnavailable}), not a reason to route around it.
 */
class VideoServiceClient implements VideoClient
{
    public function __construct(
        private string $baseUrl,
        private string $trustKey,
        private int $timeout = 15,
        private ?Guzzle $http = null,
    ) {
        $this->http ??= new Guzzle(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout]);
    }

    public function createRoom(string $externalRef, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt, array $options = []): VideoRoom
    {
        return VideoRoom::fromArray($this->send('POST', 'api/v1/rooms', array_filter([
            'external_ref' => $externalRef,
            'starts_at' => $startsAt->format(DATE_ATOM),
            'ends_at' => $endsAt->format(DATE_ATOM),
            'max_participants' => $options['max_participants'] ?? null,
            'transcription' => $options['transcription'] ?? null,
            'knocking' => $options['knocking'] ?? null,
        ], fn ($v) => $v !== null)));
    }

    public function room(string $name): VideoRoom
    {
        return VideoRoom::fromArray($this->send('GET', 'api/v1/rooms/'.rawurlencode($name)));
    }

    public function rescheduleRoom(string $name, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): VideoRoom
    {
        return VideoRoom::fromArray($this->send('PATCH', 'api/v1/rooms/'.rawurlencode($name), [
            'starts_at' => $startsAt->format(DATE_ATOM),
            'ends_at' => $endsAt->format(DATE_ATOM),
        ]));
    }

    public function setExpiry(string $name, \DateTimeInterface $expiresAt): VideoRoom
    {
        return VideoRoom::fromArray($this->send('PATCH', 'api/v1/rooms/'.rawurlencode($name), [
            'expires_at' => $expiresAt->format(DATE_ATOM),
        ]));
    }

    public function repairRoom(string $name, ?\DateTimeInterface $joinableUntil = null, bool $revive = false, bool $openNow = false, ?string $person = null, ?string $linkId = null): array
    {
        $body = array_filter([
            'joinable_until' => $joinableUntil?->format(\DateTimeInterface::ATOM),
            'revive' => $revive ?: null,
            'open_now' => $openNow ?: null,
            // Who was at the door: the service keeps a repair that found something as their issue.
            'person' => $person,
            'link_id' => $linkId,
        ], fn ($v) => $v !== null);

        $data = $this->send('POST', 'api/v1/rooms/'.rawurlencode($name).'/repair', $body);

        return [
            'room' => VideoRoom::fromArray($data),
            'issues' => (array) ($data['issues'] ?? []),
            'actions' => (array) ($data['actions'] ?? []),
        ];
    }

    public function endRoom(string $name, ?string $endedBy = null): VideoRoom
    {
        return VideoRoom::fromArray($this->send('POST', 'api/v1/rooms/'.rawurlencode($name).'/end', array_filter([
            'ended_by' => $endedBy,
        ], fn ($v) => $v !== null)));
    }

    public function endIfIdle(string $name, ?int $idleMinutes = null): array
    {
        $data = $this->send('POST', 'api/v1/rooms/'.rawurlencode($name).'/end-if-idle', array_filter([
            'idle_minutes' => $idleMinutes,
        ], fn ($v) => $v !== null));

        return ['ended' => (bool) ($data['ended'] ?? false), 'reason' => (string) ($data['reason'] ?? '')];
    }

    public function deleteRoom(string $name): void
    {
        try {
            $this->send('DELETE', 'api/v1/rooms/'.rawurlencode($name));
        } catch (RoomNotFound) {
            // Already gone from the registry: the outcome the caller asked for.
        }
    }

    public function adoptRoom(string $name, string $externalRef, array $window = []): VideoRoom
    {
        return VideoRoom::fromArray($this->send('POST', 'api/v1/rooms/adopt', array_filter([
            'name' => $name,
            'external_ref' => $externalRef,
            'starts_at' => $window['starts_at'] ?? null,
            'ends_at' => $window['ends_at'] ?? null,
            'expires_at' => $window['expires_at'] ?? null,
            'domain' => $window['domain'] ?? null,
        ], fn ($v) => $v !== null)));
    }

    public function token(string $roomName, ?string $participantId, string $displayName, bool $isOwner, ?\DateTimeInterface $expiresAt = null, bool $autoStartTranscription = false, bool $hidden = false): VideoToken
    {
        return VideoToken::fromArray($this->send('POST', 'api/v1/rooms/'.rawurlencode($roomName).'/tokens', array_filter([
            'participant_id' => $participantId,
            'display_name' => $displayName,
            'is_owner' => $isOwner,
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'auto_start_transcription' => $autoStartTranscription ?: null,
            'hidden' => $hidden ?: null,
        ], fn ($v) => $v !== null)));
    }

    public function state(string $roomName): VideoCallState
    {
        return VideoCallState::fromArray($this->send('GET', 'api/v1/rooms/'.rawurlencode($roomName).'/state'));
    }

    public function attendance(string $roomName): VideoAttendance
    {
        return VideoAttendance::fromArray($this->send('GET', 'api/v1/rooms/'.rawurlencode($roomName).'/attendance'));
    }

    public function verdict(string $roomName, array $hosts, array $guests): VideoVerdict
    {
        return VideoVerdict::fromArray($this->send('POST', 'api/v1/rooms/'.rawurlencode($roomName).'/verdict', [
            'hosts' => array_values($hosts),
            'guests' => array_values($guests),
        ]));
    }

    public function presence(string $roomName, string $participantId, ?\DateTimeInterface $at = null): void
    {
        $this->send('POST', 'api/v1/rooms/'.rawurlencode($roomName).'/presence', array_filter([
            'participant_id' => $participantId,
            'at' => $at?->format(DATE_ATOM),
        ], fn ($v) => $v !== null));
    }

    public function transcripts(string $roomName): array
    {
        $data = $this->send('GET', 'api/v1/rooms/'.rawurlencode($roomName).'/transcripts');

        return array_map(fn (array $t) => VideoTranscript::fromArray($t), (array) ($data['transcripts'] ?? []));
    }

    public function transcriptStatus(string $roomName): VideoTranscriptStatus
    {
        return VideoTranscriptStatus::fromArray($this->send('GET', 'api/v1/rooms/'.rawurlencode($roomName).'/transcript-status'));
    }

    public function transcript(string $transcriptId): VideoTranscript
    {
        return VideoTranscript::fromArray($this->send('GET', 'api/v1/transcripts/'.rawurlencode($transcriptId)));
    }

    public function callTranscripts(string $callRef): array
    {
        $data = $this->send('GET', 'api/v1/calls/'.rawurlencode($callRef).'/transcripts');

        return array_map(fn (array $t) => CallTranscript::fromArray($t), (array) ($data['transcripts'] ?? []));
    }

    public function supplyTranscript(string $callRef, string $text, ?string $language = null, ?string $suppliedBy = null): CallTranscript
    {
        return CallTranscript::fromArray($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/transcripts', array_filter([
            'text' => $text,
            'language' => $language,
            'supplied_by' => $suppliedBy,
        ], fn ($v) => $v !== null)));
    }

    public function importTranscript(string $callRef, array $transcript): CallTranscript
    {
        return CallTranscript::fromArray($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/transcripts/import', array_filter($transcript, fn ($v) => $v !== null)));
    }

    public function saveCleanText(string $transcriptId, ?string $cleanText): CallTranscript
    {
        // `clean_text` must be PRESENT (null clears it), so it is never filtered out.
        return CallTranscript::fromArray($this->request('PATCH', 'api/v1/transcripts/'.rawurlencode($transcriptId), ['json' => ['clean_text' => $cleanText]]));
    }

    public function reportJoinIssue(string $callRef, array $issue): CallJoinIssue
    {
        return CallJoinIssue::fromArray($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/issues', array_filter($issue, fn ($v) => $v !== null)));
    }

    public function joinIssues(string $callRef): array
    {
        $data = $this->send('GET', 'api/v1/calls/'.rawurlencode($callRef).'/issues');

        return array_map(fn (array $i) => CallJoinIssue::fromArray($i), (array) ($data['issues'] ?? []));
    }

    public function joinLinks(string $callRef): array
    {
        return self::links($this->send('GET', 'api/v1/calls/'.rawurlencode($callRef).'/join-links'));
    }

    public function issueJoinLinks(string $callRef, array $audiences): array
    {
        return self::links($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/join-links', ['audiences' => array_values($audiences)]));
    }

    public function rotateJoinLinks(string $callRef, array $audiences): array
    {
        return self::links($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/join-links/rotate', ['audiences' => array_values($audiences)]));
    }

    public function revokeJoinLinks(string $callRef, string $reason = 'manual'): int
    {
        return (int) ($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/join-links/revoke', ['reason' => $reason])['revoked'] ?? 0);
    }

    public function resolveJoinLink(string $token): ?JoinLink
    {
        try {
            return JoinLink::fromArray($this->send('POST', 'api/v1/join-links/resolve', ['token' => $token]));
        } catch (Exceptions\RoomNotFound) {
            return null;
        }
    }

    public function importJoinLink(string $callRef, array $link): JoinLink
    {
        return JoinLink::fromArray($this->send('POST', 'api/v1/calls/'.rawurlencode($callRef).'/join-links/import', array_filter($link, fn ($v) => $v !== null)));
    }

    public function roomForCall(string $callRef): ?VideoRoom
    {
        try {
            return VideoRoom::fromArray($this->send('GET', 'api/v1/calls/'.rawurlencode($callRef).'/room'));
        } catch (Exceptions\RoomNotFound) {
            return null;
        }
    }

    public function rekeyCall(string $fromRef, string $toRef): array
    {
        $moved = (array) ($this->send('POST', 'api/v1/calls/'.rawurlencode($fromRef).'/rekey', ['to' => $toRef])['moved'] ?? []);

        return ['rooms' => (int) ($moved['rooms'] ?? 0), 'transcripts' => (int) ($moved['transcripts'] ?? 0), 'issues' => (int) ($moved['issues'] ?? 0), 'links' => (int) ($moved['links'] ?? 0)];
    }

    /** @return array<int, JoinLink> */
    private static function links(array $data): array
    {
        return array_map(fn (array $l) => JoinLink::fromArray($l), (array) ($data['links'] ?? []));
    }

    public function usage(?string $month = null): VideoUsageReport
    {
        return new VideoUsageReport($this->send('GET', 'api/v1/usage', $month !== null ? ['month' => $month] : []));
    }

    public function usageSessions(?\DateTimeInterface $since = null, int $limit = 200): array
    {
        $data = $this->send('GET', 'api/v1/usage/sessions', array_filter([
            'since' => $since?->format(DATE_ATOM),
            'limit' => $limit,
        ], fn ($v) => $v !== null));

        return array_map(fn (array $r) => VideoUsageSession::fromArray($r), (array) ($data['sessions'] ?? []));
    }

    public function policy(): VideoPolicy
    {
        return VideoPolicy::fromArray($this->send('GET', 'api/v1/policy'));
    }

    public function roomHealth(): array
    {
        return $this->send('GET', 'api/v1/rooms/health');
    }

    public function forwardDailyWebhook(string $rawBody, array $headers): array
    {
        $pass = [];
        foreach (['X-Webhook-Signature', 'X-Webhook-Timestamp'] as $name) {
            $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && $value !== '') {
                $pass[$name] = $value;
            }
        }

        // The RAW bytes, unchanged: the service verifies Daily's HMAC over them.
        return $this->request('POST', 'api/v1/webhooks/daily', ['body' => $rawBody, 'headers' => $pass + ['Content-Type' => 'application/json']]);
    }

    /** @return array<string, mixed> the response's `data` */
    private function send(string $method, string $path, array $body = []): array
    {
        $options = $method === 'GET' ? ['query' => $body] : ($body === [] ? [] : ['json' => $body]);

        return $this->request($method, $path, $options);
    }

    private function request(string $method, string $path, array $options): array
    {
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => 'Bearer '.$this->trustKey,
            'Accept' => 'application/json',
        ];
        $options['http_errors'] = false;

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (ConnectException $e) {
            throw new VideoServiceUnavailable('video-service is unreachable: '.$e->getMessage(), 'unreachable', 0);
        } catch (GuzzleException $e) {
            throw new VideoServiceUnavailable('video-service request failed: '.$e->getMessage(), 'transport', 0);
        }

        $json = json_decode((string) $response->getBody(), true);
        $json = is_array($json) ? $json : [];

        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            return (array) ($json['data'] ?? []);
        }

        throw self::errorFor($response, $json);
    }

    /** ONE translation from the service's error codes to the typed exceptions. */
    public static function errorFor(ResponseInterface $response, array $json): VideoServiceException
    {
        $status = $response->getStatusCode();
        $code = is_string($json['error'] ?? null) ? $json['error'] : null;
        $message = (string) ($json['message'] ?? "video-service answered {$status}");

        return match (true) {
            $code === 'over_budget' => new VideoOverBudget($message, $code, $status),
            in_array($code, ['room_stranded', 'room_deleted'], true) => new RoomStranded($message, $code, $status),
            in_array($code, ['room_not_found', 'transcript_not_found'], true) || $status === 404 => new RoomNotFound($message, $code, $status),
            // Daily refused on the merits (a bad property): fixing the request helps, retrying does not.
            in_array($code, ['daily_rejected', 'invalid_signature', 'invalid_event', 'forbidden'], true) => new VideoRequestRejected($message, $code, $status),
            $status === 401 =>new VideoServiceUnavailable('video-service refused the trust key (is VIDEO_SERVICE_TRUST_KEY set?)', $code ?? 'unauthorized', $status),
            $status >= 500 || $status === 429 => new VideoServiceUnavailable($message, $code, $status),
            default => new VideoRequestRejected($message, $code, $status),
        };
    }
}
