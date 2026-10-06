<?php

namespace Shirahcan\VideoClient;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * "When can this call be joined", the same rule in every product, from the service's numbers.
 *
 * Pure: no HTTP, so a list of fifty meetings costs nothing. A call WITH a room should still
 * render the room's own state (/v1/rooms/{name}/state), which also knows a host's early end;
 * this is for everything that has to answer without a room: lists, cards, a call with no room
 * yet, the link on an email.
 */
final class CallWindow
{
    public function __construct(private readonly VideoPolicy $policy) {}

    public function policy(): VideoPolicy
    {
        return $this->policy;
    }

    public function opensAt(DateTimeInterface $start): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($start)->modify("-{$this->policy->opensBeforeMinutes} minutes");
    }

    /** The normal close, or a later one the host asked for ("Need more time?"). */
    public function closesAt(DateTimeInterface $end, ?DateTimeInterface $extendedUntil = null): DateTimeImmutable
    {
        $normal = DateTimeImmutable::createFromInterface($end)->modify("+{$this->policy->closesAfterMinutes} minutes");
        if ($extendedUntil === null) {
            return $normal;
        }
        $extended = DateTimeImmutable::createFromInterface($extendedUntil);

        return $extended > $normal ? $extended : $normal;
    }

    public function isOpen(DateTimeInterface $start, DateTimeInterface $end, ?DateTimeInterface $extendedUntil = null, ?DateTimeInterface $now = null): bool
    {
        $now = $now !== null ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable();

        return $now >= $this->opensAt($start) && $now <= $this->closesAt($end, $extendedUntil);
    }

    /** Past this, a link reads "expired" rather than "this call has ended". */
    public function expiresAt(DateTimeInterface $end, ?DateTimeInterface $extendedUntil = null): DateTimeImmutable
    {
        return $this->closesAt($end, $extendedUntil)->modify("+{$this->policy->expiredAfterCloseMinutes} minutes");
    }

    public function isExpired(DateTimeInterface $end, ?DateTimeInterface $extendedUntil = null, ?DateTimeInterface $now = null): bool
    {
        $now = $now !== null ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable();

        return $now > $this->expiresAt($end, $extendedUntil);
    }

    /** The latest a host may keep the room open with "Need more time?". */
    public function latestExtension(DateTimeInterface $end): DateTimeImmutable
    {
        return $this->closesAt($end)->modify("+{$this->policy->extensionMaxExtraMinutes} minutes");
    }

    public function allowsExtensionStep(int $minutes): bool
    {
        return in_array($minutes, $this->policy->extensionSteps, true);
    }
}
