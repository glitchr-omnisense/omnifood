<?php

namespace Omnifood\Policy;

use Omnifood\Model\Hours;
use Omnifood\Model\StoreStatus;
use Omnifood\StoreInterface;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * A platform's store behind its guard: "status", "pause", "resume", "setHours".
 *
 * @extends Guarded<StoreInterface>
 */
final class GuardedStore extends Guarded implements StoreInterface
{
    public function __construct(StoreInterface $platform, PolicyGuard $guard)
    {
        parent::__construct($platform, $guard);
    }

    public function status(): StoreStatus
    {
        return $this->guard->call('status', fn () => $this->platform->status());
    }

    public function pause(?\DateTimeImmutable $until = null, ?string $reason = null): void
    {
        $this->guard->call('pause', fn () => $this->platform->pause($until, $reason));
    }

    public function resume(): void
    {
        $this->guard->call('resume', fn () => $this->platform->resume());
    }

    public function setHours(Hours $hours): void
    {
        $this->guard->call('setHours', fn () => $this->platform->setHours($hours));
    }
}
