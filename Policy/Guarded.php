<?php

namespace Omnifood\Policy;

use Omnifood\Channel;
use Omnifood\Model\Capabilities;
use Omnifood\PlatformInterface;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * A platform behind its guard: what every Guarded* shares - its name,
 * channel and capabilities as they are, the guard, the platform itself
 * (inner()). Each call of the contract is the operation of its method's
 * name ("accept", "pushMenu", "createReservation"...): checked against the
 * policy's limits counting it before anything is sent, counted after,
 * answered or failed. The guard never retries a call.
 *
 * @template P of PlatformInterface the contract it keeps
 */
abstract class Guarded implements PlatformInterface
{
    /** @param P $platform */
    public function __construct(protected readonly PlatformInterface $platform, protected readonly PolicyGuard $guard)
    {
    }

    public function getName(): string
    {
        return $this->platform->getName();
    }

    public function getChannel(): Channel
    {
        return $this->platform->getChannel();
    }

    public function capabilities(): Capabilities
    {
        return $this->platform->capabilities();
    }

    /** @return P the platform, unguarded */
    public function inner(): PlatformInterface
    {
        return $this->platform;
    }

    public function guard(): PolicyGuard
    {
        return $this->guard;
    }
}
