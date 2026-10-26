---
title: Reservations
order: 33
---

# Reservations

`ReservationsInterface`: `reservations($from, $to)`, `reservation($ref)`,
`createReservation(Reservation)`, `updateReservation(Reservation)`,
`cancelReservation($ref, $reason)`, `setStatus($ref, ReservationStatus)`,
`pushAvailability(list<Slot>)`. The writing methods are named after the reservation so that one
platform may take orders and reservations both. TheFork and Zenchef implement it.

```php
use Omnifood\Model\Guest;
use Omnifood\Model\Reservation;

$taken = new Reservation(new \DateTimeImmutable('2026-10-12 19:30'), 4,
    new Guest('Jo', 'Martin', 'jo@example.com', '+33612345678', 'fr_FR'), notes: 'A high chair');
$recorded = $thefork->createReservation($taken);          // its reference at TheFork
$thefork->updateReservation($recorded->with(covers: 5));
$thefork->cancelReservation($recorded->reference);

foreach ($thefork->reservations(new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow')) as $r) {
    echo $r->date->format('H:i'), ' ', $r->covers, ' ', $r->guest->name(), ' ', $r->status->value, "\n";
}
```

`reservations($from, $to)` gives those whose date falls between the two instants, oldest first.
`createReservation()` answers the reservation as the platform recorded it, its `reference` set.
`updateReservation()` takes a reservation whose `reference` says which. The models are described
in [Concepts](concepts.md#reservations).

## What each platform does

| | TheFork | Zenchef |
|---|---|---|
| `reservations()` | yes: the ids booked by meal date, each read (and its customer) | refused: documentation on request |
| `reservation()` | yes, and the guest's profile (option `with_customers`) | refused |
| `createReservation()` | yes: date, party size, guest, the guest's note, the staff's note (`raw['restaurantNote']`), an offer (`raw['offerUuid']`) | refused |
| `updateReservation()` | the date, the party size and the staff's note only | refused |
| `cancelReservation()` | yes, as the restaurant; the reason is not sent | refused |
| `setStatus()` | `CANCELLED` only (cancels); the service's statuses are read, not written | refused |
| `pushAvailability()` | each slot opened (covers > 0) or closed (0) to online booking | refused |
| Deposits, tables, durations | not in the public API: `null` | - |

TheFork's statuses as Omnifood's: `RECORDED` is `CONFIRMED`, or `ARRIVED` (meal status
`PARTIALLY_ARRIVED`, `ARRIVED`), `SEATED` (`SEATED`, `BILL`), `FINISHED` (`LEFT`); `REQUESTED`,
`CANCELED` (`CANCELLED`), `NO_SHOW`, `REFUSED`; anything else `UNKNOWN`. TheFork's manual has
every field.

`Slot` covers are a number of places in the model; TheFork takes only open or closed, so any
number above 0 opens the slot.
