---
title: Concepts
order: 20
---

# Concepts

## Platform and its capabilities

`Omnifood\PlatformInterface` is one platform's client, for one restaurant:

| Method | Returns |
|---|---|
| `getName(): string` | the name it is configured as: `ubereats`, `deliveroo`, `justeat`, `thefork`, `zenchef` |
| `getChannel(): Channel` | the platform, as the `channel` of every order, reservation and notification it returns |
| `capabilities(): Capabilities` | what the platform takes and imposes ([below](#capabilities)); needs no key |

A platform implements, beside it, the capability interfaces of what it offers. Each extends
`PlatformInterface`:

| Interface | Methods | Usage |
|---|---|---|
| `OrdersInterface` | `order(string $ref): Order`, `orders(?\DateTimeImmutable $since = null): list<Order>`, `accept(string $ref, ?\DateTimeImmutable $readyAt = null): void`, `deny(string $ref, DenyReason $reason, ?string $note = null): void`, `ready(string $ref): void`, `cancel(string $ref, DenyReason $reason, ?string $note = null): void` | [Orders](orders.md) |
| `MenuInterface` | `pushMenu(Menu $menu): void`, `setAvailability(string $itemRef, bool $available, ?\DateTimeImmutable $until = null): void` | [Menu](menu.md) |
| `StoreInterface` | `status(): StoreStatus`, `pause(?\DateTimeImmutable $until = null, ?string $reason = null): void`, `resume(): void`, `setHours(Hours $hours): void` | [Store](store.md) |
| `ReservationsInterface` | `reservations(\DateTimeImmutable $from, \DateTimeImmutable $to): list<Reservation>`, `reservation(string $ref): Reservation`, `createReservation(Reservation $r): Reservation`, `updateReservation(Reservation $r): Reservation`, `cancelReservation(string $ref, ?string $reason = null): void`, `setStatus(string $ref, ReservationStatus $status): void`, `pushAvailability(list<Slot> $slots): void` | [Reservations](reservations.md) |
| `NotifiableInterface` | `notify(string $body, array $headers): Notification` | [Webhooks](webhooks.md) |

And for the platforms that hold a token, in `Omnifood\Auth` (these do not extend
`PlatformInterface`):

| Interface | Methods | Implemented by |
|---|---|---|
| `RefreshableInterface` | `token(): ?Token` (the one held, `null` before the first call), `refresh(): Token` (a new one, asked for now) | Uber Eats, Deliveroo, TheFork |
| `OAuthInterface` | `authorizationUrl(string $redirectUri, string $state, list<string> $scopes = []): string`, `exchange(string $code, string $redirectUri): Token` | Uber Eats |

A platform asks for its token itself when a call needs one; `token()` and `refresh()` are for a
site that keeps the token between processes ([Configuration](configuration.md#tokens)).

`$ref` is always the platform's own id for the order or the reservation. A method a platform
must have, as its interface requires, but cannot do - the platform does not offer it, or does not
document how - throws `NotSupportedException` before anything is sent. The method names that
write a reservation carry the word (`createReservation()`, `cancelReservation()`) so that one
platform may implement both `OrdersInterface` and `ReservationsInterface`:
`OrdersInterface::cancel()` is an order's.

## Factory and Config

`Omnifood\PlatformFactoryInterface` builds a platform from options:

| Method | |
|---|---|
| `getName(): string` | the name platforms are configured with (`ubereats`...) |
| `create(array $options = []): PlatformInterface` | the platform, built |

Every platform package extends `Omnifood\PlatformFactory`, which takes an optional
`Symfony\Contracts\HttpClient\HttpClientInterface` in its constructor (none: the platform calls
`HttpClient::create()` itself) and does the work in `create()`:

1. `createConfig($options)` - the options with every `null` and `''` removed, in a `Config` (an
   `ArrayObject`); then the factory's `populate()` sets its name, its required options and the
   default of each option not given (`Config::defaults()` sets only keys not set yet);
2. the keys listed in `omnifood.required_options` are checked: one missing, `''` or `[]` is an
   `InvalidConfigException` listing every missing key (`The "x" platform needs: a, b.`);
3. `build($config)` constructs the platform.

`getName()` is `createConfig()['omnifood.factory_name']`. The reserved keys:

| Key | |
|---|---|
| `omnifood.factory_name` | the platform's name |
| `omnifood.required_options` | list of option names that must be filled; `[]` for the five packages, which check a key when a call needs it |

`Config::get($key, $default)` reads a key, `Config::validateNotEmpty($keys)` is step 2.

## Registry

`Omnifood\Registry` holds the restaurant's platforms by name:

```php
$registry = new Registry(
    [new UberEatsPlatformFactory($http), new TheForkPlatformFactory($http)],   // iterable of factories, keyed by getName()
    [
        'ubereats' => ['factory' => 'ubereats', 'options' => [/* ... */]],
        'thefork' => ['factory' => 'thefork', 'options' => [/* ... */]],
    ],
);
```

| Method | Returns |
|---|---|
| `get(string $name): PlatformInterface` | the platform, built the first time and kept |
| `create(string $name, array $overrides = []): PlatformInterface` | a platform built afresh, its configured options with `$overrides` over them (`array_replace`); not kept |
| `has(string $name): bool` | whether the name is configured (nothing is built) |
| `options(string $name): array` | its configured options (`[]` for an unknown name) |
| `names(): list<string>` | the configured names, in configuration order |
| `all(): array<string, PlatformInterface>` | every configured platform, built |
| `orders()`, `menus()`, `stores()`, `reservations()`, `notifiables()` | the configured platforms that implement `OrdersInterface`, `MenuInterface`, `StoreInterface`, `ReservationsInterface`, `NotifiableInterface` (built), as lists |
| `factories(): list<string>` | the names of the factories given |

`get()` and `create()` throw `InvalidConfigException` for a name not configured
(`No "nope" platform; configured: shop.`) and for a factory not given
(`No "deliveroo" factory for the "shop" platform; installed: ubereats.`). The lists by capability
call `all()`, so they build every configured platform; a platform that implements an interface
and refuses some of its methods (Just Eat's `orders()`) is in the list all the same.

## Channel

`Omnifood\Channel` (string enum) is where an order or a reservation comes from:

| Case | Value | `label()` |
|---|---|---|
| `UBEREATS` | `ubereats` | Uber Eats |
| `DELIVEROO` | `deliveroo` | Deliveroo |
| `JUSTEAT` | `justeat` | Just Eat |
| `THEFORK` | `thefork` | TheFork |
| `ZENCHEF` | `zenchef` | Zenchef |
| `DOORDASH` | `doordash` | DoorDash |
| `WOLT` | `wolt` | Wolt |
| `GLOVO` | `glovo` | Glovo |
| `OPENTABLE` | `opentable` | OpenTable |
| `SEVENROOMS` | `sevenrooms` | SevenRooms |
| `DIRECT` | `direct` | Direct (the restaurant's own site, the phone, the walk-in) |

DoorDash, Wolt, Glovo, OpenTable and SevenRooms have no package yet: the cases let an application
keep its own orders and bookings from them in the same models.

## Capabilities

`Omnifood\Model\Capabilities` (readonly) is what a platform takes and imposes, for a back office
to show and the [validator](menu.md#checking-it) to check a menu against. What the platform does
not say is `null` (no limit known).

| Property | Type | Default | |
|---|---|---|---|
| `orderTypes` | `list<OrderType>` | `[]` | the order types it takes; `takes(OrderType)` asks |
| `acceptanceDelay` | `?int` | `null` | seconds the restaurant has to accept a new order before the platform cancels it |
| `readyAt` | `bool` | `false` | whether `accept()` sends when the order will be ready |
| `modifierDepth` | `int` | `1` | how deep modifiers nest: 0 none, 1 an item's groups, 2 a modifier's own groups |
| `allergens` | `bool` | `false` | whether the menu carries the EU-14 allergens |
| `photos` | `bool` | `false` | whether items carry a photo |
| `vatRates` | `bool` | `false` | whether each item carries its VAT rate |
| `nameMaxLength` | `?int` | `null` | the longest name, in characters |
| `descriptionMaxLength` | `?int` | `null` | the longest description |
| `pauseUntil` | `bool` | `false` | whether `pause()` sends an end |
| `deposits` | `bool` | `false` | whether a reservation may carry a deposit or a card guarantee |
| `availabilityPush` | `bool` | `false` | whether the restaurant can push its free tables (`pushAvailability()`) |
| `currencies` | `list<string>` | `[]` | ISO 4217 codes accepted; `[]` any |
| `modifiersAreItems` | `bool` | `false` | whether a modifier is a menu item of its own on the platform (its reference shares the items' space) |

What each platform answers (probed on 2026-10-26):

| | Uber Eats | Deliveroo | Just Eat | TheFork | Zenchef |
|---|---|---|---|---|---|
| `orderTypes` | delivery, pickup, dine_in | delivery, pickup, dine_in | delivery, pickup | - | - |
| `acceptanceDelay` | 690 | 600 | `null` | `null` | `null` |
| `readyAt` | yes | no | no | no | no |
| `modifierDepth` | 2 | 2 | 2 | 1 | 1 |
| `allergens`, `photos` | yes | yes | yes | no | no |
| `vatRates` | yes | yes | no (a VAT category) | no | no |
| `nameMaxLength` / `descriptionMaxLength` | - | 120 / 500 | - | - | - |
| `pauseUntil` | yes | no | yes | no | no |
| `availabilityPush` | no | no | no | yes | no |
| `modifiersAreItems` | yes | yes | no | no | no |

## Money

`Omnifood\Model\Money` (readonly): an `int $amount` in the currency's minor units and a
`string $currency`, upper-cased by the constructor. `Money::of(1250, 'EUR')` is 12.50 EUR.

| Method | |
|---|---|
| `of(int $amount, string $currency): self` | the constructor, static |
| `equals(Money $other): bool` | same amount and currency |
| `decimal(): string` | in major units, as a decimal API wants it: `"12.50"`; `"1250"` for JPY, `"1.250"` for KWD |
| `fromDecimal(string\|float $decimal, string $currency): self` | from a decimal, rounded to minor units |
| `minorDigits(string $currency): int` | 0 for BIF, CLP, DJF, GNF, ISK, JPY, KMF, KRW, MGA, PYG, RWF, UGX, UYI, VND, VUV, XAF, XOF, XPF; 3 for BHD, IQD, JOD, KWD, LYD, OMR, TND; 2 otherwise |
| `__toString()` | `"12.50 EUR"` |

## Orders

`Omnifood\Model\Order` (readonly), the same shape for every platform. What the platform does not
tell is `null`; `$raw` keeps the platform's answer.

| Property | Type | |
|---|---|---|
| `channel` | `Channel` | the platform |
| `reference` | `string` | the platform's id: what every call on the order takes |
| `displayId` | `?string` | the short number the courier and the customer say (`A1B2C`, `#42`) |
| `type` | `OrderType` | how it leaves |
| `status` | `OrderStatus` | where it stands |
| `lines` | `list<Line>` | what was ordered |
| `customer` | `?Customer` | who ordered |
| `total` | `?Money` | what the customer paid |
| `subtotal` | `?Money` | the items, before fees and discounts |
| `discount` | `?Money` | discounts and promotions, as a positive amount |
| `fees` | `?Money` | delivery, service and bag fees |
| `tax` | `?Money` | the tax, where the platform says |
| `placedAt` | `?\DateTimeImmutable` | when it was placed |
| `pickupAt` | `?\DateTimeImmutable` | when it should be ready (collected by the courier or the customer) |
| `deliverAt` | `?\DateTimeImmutable` | when it should reach the customer, for a delivery |
| `courier` | `?Courier` | who collects it |
| `note` | `?string` | the customer's note for the whole order (allergies included where the platform has no field of its own) |
| `store` | `?string` | the store (restaurant) id on the platform |
| `cutlery` | `?bool` | cutlery asked for; no package fills it yet |
| `raw` | `array` | the platform's answer |

`count(): int` is the number of items, modifiers not counted (the sum of the lines' quantities).

`Line`: `name` (string), `quantity` (int), `unitPrice` and `total` (`?Money`, the total with the
modifiers, as the platform counts it), `posRef` (the restaurant's reference, `Menu\Item::$ref`,
when the platform carries it back), `platformRef` (the platform's id for the line or the item),
`modifiers` (`list<Modifier>`), `note` (the customer's words for this line).

`Modifier` (an order's): `name`, `quantity` (default 1), `price` (`?Money`, of one, on top of the
item's), `posRef`, `group` (the modifier group's name, when the platform says), `modifiers`
(nested choices).

`Customer`: `name`, `phone`, `phoneCode` (an extension to dial after the platform's masked
number), `email`, `address` (one line, when the restaurant delivers itself) - all `?string`.

`Courier`: `name`, `phone`, `status` (the platform's own word: `ARRIVED_AT_PICKUP`...),
`arrivesAt` (`?\DateTimeImmutable`), `vehicle`.

`OrderType`: `DELIVERY` (`delivery`, by the platform's courier or the restaurant's own), `PICKUP`
(`pickup`, collected at the counter), `DINE_IN` (`dine_in`).

`OrderStatus`:

| Case | Value | |
|---|---|---|
| `NEW` | `new` | sent to the restaurant, waiting for its answer |
| `ACCEPTED` | `accepted` | |
| `READY` | `ready` | ready for the courier or the customer |
| `PICKED_UP` | `picked_up` | collected: on its way, or in the customer's hands |
| `DELIVERED` | `delivered` | |
| `DENIED` | `denied` | refused by the restaurant before it was accepted |
| `CANCELLED` | `cancelled` | |
| `UNKNOWN` | `unknown` | the platform's word is not one of these |

`isFinal()` is true for `DELIVERED`, `DENIED` and `CANCELLED`.

`DenyReason`, why the restaurant refuses or cancels, mapped by each platform to its own codes
([Orders](orders.md#deny-reasons)): `ITEM_UNAVAILABLE`, `CLOSED`, `TOO_BUSY`, `CUSTOMER_REQUEST`,
`ADDRESS`, `PRICING`, `SPECIAL_INSTRUCTIONS`, `TECHNICAL`, `OTHER` (values in snake_case:
`item_unavailable`...).

## Menu

The menu as a site holds it, in `Omnifood\Model\Menu` (all readonly). Every `ref` is the
restaurant's own (its till's): the platforms carry it back on the orders.

`Menu`: `name` (string), `categories` (`list<Category>`), `currency` (default `EUR`), `hours`
(`?Hours`, when it is served; `null`: whenever the store is open), `ref` (`?string`),
`description` (`?string`). `items()` lists every item, in the order of the categories.

`Category`: `ref`, `name`, `items` (`list<Item>`), `description`.

`Item`, a dish or a drink:

| Property | Type | Default | |
|---|---|---|---|
| `ref` | `string` | - | the restaurant's reference, the one `setAvailability()` and the orders take |
| `name` | `string` | - | |
| `price` | `Money` | - | |
| `description` | `?string` | `null` | |
| `vatRate` | `?float` | `null` | in percent: `10.0`, `5.5` |
| `allergens` | `list<Allergen>` | `[]` | |
| `photo` | `?string` | `null` | a public URL the platform fetches |
| `modifierGroups` | `list<ModifierGroup>` | `[]` | |
| `available` | `bool` | `true` | |
| `labels` | `list<string>` | `[]` | what the model has no field for: `vegetarian`, `vegan`, `gluten_free`, `alcohol`, `no_allergens`, a platform's own allergen codes ([Menu](menu.md#labels)) |

`ModifierGroup`, a choice on an item: `ref`, `name`, `modifiers` (`list<Modifier>`), `min`
(default 0), `max` (`?int`, `null`: as many as there are). `isRequired()` is `min > 0`.

`Modifier` (a menu's), one option of a group: `ref`, `name`, `price` (`?Money`, on top of the
item's; `null` or zero: free), `allergens`, `available` (default `true`), `modifierGroups` (groups
of its own: a set menu's main course, and its cooking), `vatRate` (`?float`; `null`: the item's).

`Allergen`, the fourteen of EU Regulation 1169/2011, Annex II: `GLUTEN` (Cereals containing
gluten), `CRUSTACEANS`, `EGGS`, `FISH`, `PEANUTS`, `SOYBEANS`, `MILK`, `NUTS`, `CELERY`, `MUSTARD`,
`SESAME` (Sesame seeds), `SULPHITES` (Sulphur dioxide and sulphites), `LUPIN`, `MOLLUSCS`; values
in lower case, `label()` the regulation's name.

`Violation`: `path` (`categories[0].items[2].name`) and `message`, `__toString()` both
(`path: message`).

## Hours

`Omnifood\Model\Hours` (readonly): opening hours.

```php
new Hours(
    [1 => [['12:00', '14:30'], ['19:00', '22:30']], 5 => [['19:00', '01:00']]],   // day of the week => ranges
    ['2026-12-25' => [], '2026-12-31' => [['19:00', '02:00']]],                    // dates apart
);
```

- `week`: for each day, 1 Monday to 7 Sunday, its ranges `[from, to]` in `HH:MM` (00:00 to
  23:59), sorted by their start. A day not given, or with no range, is closed. A range may end
  after midnight (`['19:00', '01:00']`).
- `exceptions`: `Y-m-d` => ranges; `[]` is closed that day.
- `day(int $day)`: that day of the week's ranges.
- `on(\DateTimeInterface $date)`: that date's exception if it has one, else its day of the week's.
- `isOpenAt(\DateTimeImmutable $at)`: whether `$at` falls in one of its date's ranges; a range
  past midnight is counted on the day it starts (Friday's 19:00-01:00 covers Saturday 00:30).

The constructor throws `\InvalidArgumentException` for a day outside 1-7 (`Day 8: 1 (Monday) to
7 (Sunday).`), a time that is not `HH:MM` (`Day 1: "24:00" is not a time, HH:MM.`), an empty range
(`Day 1: a range from 12:00 to 12:00 is empty.`) or an exception that is not a date.

## The store

`StoreStatus` (readonly): `state` (`StoreState`), `until` (`?\DateTimeImmutable`, when a pause
ends, when the platform says), `reason` (`?string`), `raw`. `isOpen()` is `state === OPEN`.

`StoreState`: `OPEN` (taking orders), `PAUSED` (within its hours, taking none for a while),
`CLOSED` (outside its hours), `UNKNOWN`.

## Reservations

`Reservation` (readonly), a table booked:

| Property | Type | Default | |
|---|---|---|---|
| `date` | `\DateTimeImmutable` | - | when the guests come, in the restaurant's time zone |
| `covers` | `int` | - | how many |
| `guest` | `Guest` | - | for whom |
| `status` | `ReservationStatus` | `CONFIRMED` | |
| `reference` | `?string` | `null` | the platform's id; `null` for a reservation not recorded yet |
| `channel` | `?Channel` | `null` | |
| `notes` | `?string` | `null` | the guest's request ("a high chair") |
| `allergies` | `?string` | `null` | |
| `table` | `?string` | `null` | the table asked for or given |
| `duration` | `?int` | `null` | minutes the table is held |
| `deposit` | `?Money` | `null` | a deposit, a prepayment, a card imprint's guarantee |
| `source` | `?string` | `null` | where it came from inside the platform: `widget`, `marketplace`... |
| `createdAt` | `?\DateTimeImmutable` | `null` | |
| `restaurant` | `?string` | `null` | the restaurant's id on the platform |
| `raw` | `array` | `[]` | the platform's answer; also extra fields a platform reads when writing ([Reservations](reservations.md)) |

`with(...$changes)` gives the same reservation with named fields replaced:
`$reservation->with(covers: 5)`.

`Guest`: `firstName`, `lastName`, `email`, `phone`, `locale` (`fr`, `en_GB`), `id` (the
platform's id for the guest) - all `?string`; `name()` is first and last names joined.

`ReservationStatus`:

| Case | Value | |
|---|---|---|
| `REQUESTED` | `requested` | asked for, waiting for the restaurant (or a deposit) |
| `CONFIRMED` | `confirmed` | |
| `ARRIVED` | `arrived` | at the door, not seated yet |
| `SEATED` | `seated` | |
| `FINISHED` | `finished` | paid and gone |
| `NO_SHOW` | `no_show` | |
| `CANCELLED` | `cancelled` | |
| `REFUSED` | `refused` | refused by the restaurant |
| `UNKNOWN` | `unknown` | |

`isFinal()` is true for `FINISHED`, `NO_SHOW`, `CANCELLED` and `REFUSED`.

`Slot` (readonly), a time a table can be booked at: `start` (`\DateTimeImmutable`), `covers` (the
covers still bookable; 0 is full), `end` (`?\DateTimeImmutable`), `area` (`?string`, the room or
terrace, when the platform sells them apart).

## Notifications

`Notification` (readonly), what a webhook said, checked:

| Property | Type | |
|---|---|---|
| `channel` | `Channel` | |
| `event` | `string` | the platform's event type: `orders.notification`, `order.new`... |
| `subject` | `NotificationSubject` | what it is about |
| `reference` | `?string` | the order's (or reservation's) id, for `order()` or `reservation()` |
| `id` | `?string` | the platform's id for the event, to handle it once (platforms retry) |
| `order` | `?Order` | the order, when the webhook carries it whole |
| `reservation` | `?Reservation` | likewise |
| `status` | `OrderStatus\|ReservationStatus\|null` | the status it announces, when it announces one |
| `occurredAt` | `?\DateTimeImmutable` | |
| `store` | `?string` | the store id it concerns |
| `raw` | `array` | the decoded body |

`NotificationSubject`: `ORDER`, `COURIER` (an order's courier: assigned, arrived, gone),
`RESERVATION`, `STORE`, `MENU`, `OTHER`.

## Token

`Omnifood\Model\Token` (its properties readonly): `accessToken` (string), `expiresAt`
(`?\DateTimeImmutable`), `refreshToken` (`?string`), `scopes` (`list<string>`), `accountId`
(`?string`).

| Method | |
|---|---|
| `isExpired(?\DateTimeImmutable $now = null): bool` | `expiresAt` reached; a token without an end never expires |
| `isExpiring(int $days = 10, ?\DateTimeImmutable $now = null): bool` | within `$days` of its end |
| `toArray(): array` | `access_token`, `expires_at` (ATOM), `refresh_token`, `scopes`, `account_id`, the unknown left out: for a database or a cache |
| `fromArray(array $data): self` | back |

## Validator

`Omnifood\Validator::validate(Menu $menu, Capabilities $capabilities): list<Violation>` checks a
menu against a platform's capabilities before anything is sent: [Menu](menu.md#checking-it).
