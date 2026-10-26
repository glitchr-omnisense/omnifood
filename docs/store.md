---
title: Store
order: 32
---

# Store

`StoreInterface`: `status()`, `pause($until, $reason)`, `resume()`, `setHours(Hours)`. Uber Eats,
Deliveroo and Just Eat implement it.

```php
use Omnifood\Model\Hours;

$platform->pause(new \DateTimeImmutable('+30 minutes'), 'Rush');   // no new order for half an hour
$platform->resume();
$platform->setHours(new Hours(
    [1 => [['12:00', '14:30'], ['19:00', '22:30']], 2 => [['12:00', '14:30']]],
    ['2026-12-25' => []],                                            // closed that day
));

$status = $platform->status();
if (!$status->isOpen()) {
    echo $status->state->value, ' until ', $status->until?->format('H:i') ?? '?', "\n";
}
```

`Hours`: per day of the week (1 Monday ... 7 Sunday) its ranges `['HH:MM', 'HH:MM']`, a range past
midnight allowed; `exceptions` per date. `isOpenAt()` and `on()` answer for a given time
([Concepts](concepts.md#hours)):

```php
$hours = new Hours(
    [5 => [['19:00', '01:00'], ['12:00', '14:30']], 6 => [['19:00', '01:00']]],
    ['2026-12-25' => []],
);
$hours->day(5);                                                 // [['12:00', '14:30'], ['19:00', '01:00']]: sorted
$hours->isOpenAt(new \DateTimeImmutable('2026-10-30 13:00'));   // true, a Friday
$hours->isOpenAt(new \DateTimeImmutable('2026-10-31 00:30'));   // true: Friday's 19:00-01:00
$hours->isOpenAt(new \DateTimeImmutable('2026-12-25 20:00'));   // false, closed that day
```

(run on 2026-10-26 in the [harness](harness.md))

## What each platform does

| | Uber Eats | Deliveroo | Just Eat |
|---|---|---|---|
| `status()` | `ONLINE` is `OPEN`, `OFFLINE` is `PAUSED`, with `is_offline_until` and `offline_reason` | `OPEN` is `OPEN`, `CLOSED` and `READY_TO_OPEN` are `CLOSED`; no end | refused: JET only pushes it (the Restaurant Temporarily Offline webhook) |
| `pause($until, $reason)` | `OFFLINE`, with its end and reason | `CLOSED`; the end and reason are not sent: `resume()` | offline until `$until` in the restaurant's local time (none: until `resume()`); the reason is not sent |
| `resume()` | `ONLINE` | `OPEN` | online |
| `setHours()` | the dates apart only, as holiday hours (each call replaces the previous ones; a closed date is 00:00-00:00); the week is the menu's service hours | the week, replaced whole; the dates apart are not sent | the same week for delivery and collection, with the configured time zone; the dates apart are not sent (a closed day is a `pause()`) |

Any other state a platform answers is `StoreState::UNKNOWN`; `StoreStatus::$raw` keeps the
answer. In AT, BE, BG, CH, DE, LU, NL, PL and SK, JET brings a restaurant back online at 07:00
Amsterdam time the next day whatever end was asked.
