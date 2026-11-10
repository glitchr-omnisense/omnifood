<?php

namespace Omnifood\Policy;

use Omnifood\Model\Reservation;
use Omnifood\Model\ReservationStatus;
use Omnifood\ReservationsInterface;
use Omnisense\Contracts\Policy\PolicyGuard;

/**
 * A platform's reservations behind its guard: "reservations", "reservation",
 * "createReservation", "updateReservation", "cancelReservation",
 * "setStatus", "pushAvailability".
 *
 * @extends Guarded<ReservationsInterface>
 */
final class GuardedReservations extends Guarded implements ReservationsInterface
{
    public function __construct(ReservationsInterface $platform, PolicyGuard $guard)
    {
        parent::__construct($platform, $guard);
    }

    public function reservations(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->guard->call('reservations', fn () => $this->platform->reservations($from, $to));
    }

    public function reservation(string $ref): Reservation
    {
        return $this->guard->call('reservation', fn () => $this->platform->reservation($ref));
    }

    public function createReservation(Reservation $reservation): Reservation
    {
        return $this->guard->call('createReservation', fn () => $this->platform->createReservation($reservation));
    }

    public function updateReservation(Reservation $reservation): Reservation
    {
        return $this->guard->call('updateReservation', fn () => $this->platform->updateReservation($reservation));
    }

    public function cancelReservation(string $ref, ?string $reason = null): void
    {
        $this->guard->call('cancelReservation', fn () => $this->platform->cancelReservation($ref, $reason));
    }

    public function setStatus(string $ref, ReservationStatus $status): void
    {
        $this->guard->call('setStatus', fn () => $this->platform->setStatus($ref, $status));
    }

    public function pushAvailability(array $slots): void
    {
        // One request a slot where the platform takes them one by one (TheFork): each counts.
        $this->guard->call('pushAvailability', fn () => $this->platform->pushAvailability($slots), amounts: ['requests' => max(1, \count($slots))]);
    }
}
