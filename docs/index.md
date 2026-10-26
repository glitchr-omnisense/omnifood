# omnisense/omnifood

Omnifood is one PHP contract for the restaurant platforms: the delivery orders of Uber Eats,
Deliveroo and Just Eat, the table reservations of TheFork and Zenchef. A site holds its menu, its
hours and its bookings once; each platform's package turns them into that platform's calls, and
turns what the platform sends back - orders, reservations, webhooks - into the same models.

```php
$notification = $ubereats->notify($body, $headers);          // a webhook, checked and read
$order = $notification->order ?? $ubereats->order($notification->reference);

$ubereats->accept($order->reference, new \DateTimeImmutable('+20 minutes'));
$ubereats->ready($order->reference);                         // the courier may come

$deliveroo->pushMenu($menu);                                 // checked first, then sent whole
$deliveroo->setAvailability('gyoza', false);                 // out of stock
$justeat->pause(new \DateTimeImmutable('+30 minutes'));      // no new order for half an hour

$thefork->reservations(new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow'));
```

The platforms do different things, so there is no one big interface: a platform implements the
**capabilities** it offers - `OrdersInterface`, `MenuInterface`, `StoreInterface`,
`ReservationsInterface`, `NotifiableInterface` - and `Auth\RefreshableInterface` or
`Auth\OAuthInterface` when it holds a token. What a platform offers but its public documentation
does not show how to call throws `NotSupportedException`: nothing is guessed. The design is
Omnibus's: a platform's **factory** builds its client from options, a **registry** holds the
restaurant's platforms by name.

The core needs no framework: it requires `symfony/http-client-contracts` and nothing else. Each
platform package requires `symfony/http-client`, a library that stands alone. A Symfony bundle is
included for applications that want the wiring done ([Symfony](symfony.md)).

Published as `omnisense/omnifood`, formerly `glitchr/omnifood` and `omniscient/omnifood`: the
package `replace`s both names, so a `composer.json` that still requires one of them resolves to
this package and never installs both. The namespace is `Omnifood\`; the platforms keep the
`omnifood/` vendor and the `Omnifood\<Platform>\` namespace.

## When to use it

- A restaurant, a dark kitchen or a till that takes orders from more than one delivery platform
  and wants one order screen, one menu and one "pause" button for all of them.
- A site that keeps its own table bookings and wants those of TheFork in the same list, or records
  a phone booking on TheFork so that the table is not sold twice.
- A back office that checks a menu against each platform's rules before anything is sent
  ([the validator](menu.md#checking-it)).

It is not an aggregator service: there is no account of its own, no queue and no storage. Each
platform is called with the restaurant's own partner keys, and keeping the orders, the tokens and
the webhook ids handled is the application's job.

## Platforms

Five packages. The interfaces are what each platform's class implements (probed in the
[harness](harness.md) on 2026-10-26); "refused" means the method exists, as the interface
requires, and throws `NotSupportedException`.

| Package | Factory name | Orders | Menu | Store | Reservations | Webhooks | Tokens |
|---|---|---|---|---|---|---|---|
| [`omnifood/ubereats`](https://github.com/glitchr-omnisense/omnifood-ubereats) | `ubereats` | yes | yes | yes | - | HMAC of the body | refreshable, OAuth |
| [`omnifood/deliveroo`](https://github.com/glitchr-omnisense/omnifood-deliveroo) | `deliveroo` | yes, `cancel()` refused | yes | yes | - | HMAC of guid and body | refreshable |
| [`omnifood/justeat`](https://github.com/glitchr-omnisense/omnifood-justeat) | `justeat` | pushed only: `order()`, `orders()`, `ready()`, `cancel()` refused | yes | `status()` refused | - | key and/or HMAC | - |
| [`omnifood/thefork`](https://github.com/glitchr-omnisense/omnifood-thefork) | `thefork` | - | - | - | yes, `setStatus()` cancels only | token in the URL | refreshable |
| [`omnifood/zenchef`](https://github.com/glitchr-omnisense/omnifood-zenchef) | `zenchef` | - | - | - | every call refused | refused | - |

What each platform takes and imposes - the order types, the delay to accept, how deep modifiers
nest, name lengths - is its `capabilities()` ([Concepts](concepts.md#capabilities)), which needs
no key. Each platform's own manual lists its options, credentials and behaviour.

No platform gives API keys without a partner agreement: every package is tested against recorded
answers written from the platform's public documentation, none was called live
([Verified, and not](verified.md)).

## How this manual is organised

- [Installation](installation.md): requirements, a first script in plain PHP, a platform's API.
- [Symfony](symfony.md) and [Laravel](laravel.md): wiring the registry in a framework.
- [Concepts](concepts.md): platforms and their capabilities, factories, the registry, every model.
- Usage: [orders](orders.md), [the menu](menu.md), [the store](store.md),
  [reservations](reservations.md), [webhooks](webhooks.md).
- [Configuration reference](configuration.md): the registry, keys typed in a back office, tokens
  and OAuth, every platform's options.
- [Errors](errors.md): the exceptions and when each is thrown.
- [Testing](testing.md): testing an application that uses Omnifood.
- [The Docker harness](harness.md): every platform installed, a console to call them.
- [Writing a platform](providers.md).
- [Verified, and not](verified.md).
