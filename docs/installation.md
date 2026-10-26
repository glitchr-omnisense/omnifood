---
title: Installation
order: 10
---

# Installation

```sh
composer require omnisense/omnifood
composer require omnifood/ubereats omnifood/deliveroo omnifood/justeat omnifood/thefork omnifood/zenchef
```

Take only the platforms the restaurant is on.

## Requirements

- PHP 8.2 or later.
- `omnisense/omnifood` requires `symfony/http-client-contracts` (^3.0) and nothing else.
- Each platform package requires `omnisense/omnifood` (1.x) and `symfony/http-client` (^6.4, ^7.0
  or ^8.0), a library that stands alone and loads no framework.
- `ext-json` (always present since PHP 8.0). The [menu validator](menu.md#checking-it) and
  Deliveroo's menu check call `mb_strlen()`: `ext-mbstring` must be enabled, though no
  `composer.json` requires it.

> [!WARNING]
> Known defect: `Validator` and `DeliverooPlatform::pushMenu()` call `mb_strlen()` while no package
> requires `ext-mbstring` (nor a polyfill), where a PHP built without it fails with an undefined
> function only when a menu is checked.

Omnifood runs the same in plain PHP, in a worker, in Laravel or Slim, and in Symfony, where a
bundle does the wiring ([Symfony](symfony.md)).

## Plain PHP

A whole script: the registry built by hand, an Uber Eats order read. No platform gives keys
without a partner agreement, so the HTTP client here answers as Uber would, from a recorded answer.

```php
<?php // bare.php

require __DIR__.'/vendor/autoload.php';

use Omnifood\Registry;
use Omnifood\UberEats\UberEatsPlatformFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Uber's answers, recorded: no platform gives keys without a partner agreement.
$http = new MockHttpClient(static fn (string $method, string $url) => new MockResponse(str_contains($url, '/oauth/')
    ? '{"access_token": "recorded", "expires_in": 3600}'
    : '{"order": {"id": "o-1", "display_id": "A1B2C", "state": "OFFERED", "fulfillment_type": "DELIVERY_BY_UBER",
        "carts": [{"items": [{"id": "ramen", "title": "Shoyu ramen", "quantity": {"amount": 2}}]}]}}'));

$registry = new Registry([new UberEatsPlatformFactory($http)], [
    'ubereats' => ['factory' => 'ubereats', 'options' => ['client_id' => 'id', 'client_secret' => 'secret', 'store_id' => 'store-1']],
]);

$ubereats = $registry->get('ubereats');
echo 'Uber Eats: accept within ', $ubereats->capabilities()->acceptanceDelay, " s\n";
$order = $ubereats->order('o-1');
foreach ($order->lines as $line) {
    echo '#', $order->displayId, ' ', $order->type->value, ' ', $order->status->value, ': ', $line->quantity, ' x ', $line->name, "\n";
}
```

```
$ php bare.php
Uber Eats: accept within 690 s
#A1B2C delivery new: 2 x Shoyu ramen
```

(run on 2026-10-26 in the [harness](harness.md), PHP 8.4, every package from its checkout)

With the restaurant's keys, the client is the real one and the script calls Uber:

```php
$http = Symfony\Component\HttpClient\HttpClient::create();
$options = [
    'client_id' => getenv('UBEREATS_CLIENT_ID') ?: null,
    'client_secret' => getenv('UBEREATS_CLIENT_SECRET') ?: null,
    'store_id' => getenv('UBEREATS_STORE_ID') ?: null,
];
```

The pieces:

- a **factory** per platform package (`UberEatsPlatformFactory`, `DeliverooPlatformFactory`,
  `JustEatPlatformFactory`, `TheForkPlatformFactory`, `ZenchefPlatformFactory`), which takes the
  HTTP client to call with - the application's, a `MockHttpClient` in a test; with none given the
  platform makes its own (`HttpClient::create()`);
- the **registry**, built from the factories and the platforms' options, by name
  ([Configuration](configuration.md)): `get()` builds a platform once, `create()` builds one afresh
  with other options;
- the **platforms** it gives, each as what it does: `OrdersInterface`, `MenuInterface`,
  `StoreInterface`, `ReservationsInterface`, `NotifiableInterface` ([Concepts](concepts.md)).

One platform needs no registry: `(new UberEatsPlatformFactory($http))->create($options)`.

No class of a framework is loaded on the way: `Tests/BareTest.php` checks it in a process of its
own, and so does `docker compose run --rm omnifood bare` ([harness](harness.md#bare-no-bundle-no-container)).

## Building a platform calls nothing

A factory checks nothing and calls nothing: no platform declares a required option, so
`create()` succeeds with no key at all. A key is asked for when a call needs it, and its absence is
an `InvalidConfigException` naming it then:

```
The "ubereats" platform needs: client_id, client_secret.     (the first call that needs a token)
The "ubereats" platform needs: store_id.                     (orders(), the menu, the store)
The "justeat" platform needs: api_key.
```

So `capabilities()` and the [menu validator](menu.md#checking-it) work before an account exists,
and a site boots with its platforms declared and no keys yet. A token is asked for on the first
call that needs one, not when the platform is built.

## In a framework

- **Symfony**: `Omnifood\Bridge\Symfony\OmnifoodBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnifood.yaml` and makes
  each platform injectable by its name, as what it does: [Symfony](symfony.md). Its components
  (`symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`) are not required by
  this package: a Symfony application has them.
- **Laravel**: there is no Omnifood provider in omnibridge/laravel; a service provider of the
  application builds the registry once: [Laravel](laravel.md).
- **Any other**: build the `Registry` once, where the framework builds its services (a container
  definition, a factory), as the script above does.
