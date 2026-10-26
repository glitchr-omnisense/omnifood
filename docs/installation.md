# Installation

```sh
composer require omnisense/omnifood
composer require omnifood/ubereats omnifood/deliveroo omnifood/justeat omnifood/thefork omnifood/zenchef
```

Take only the platforms the restaurant is on. PHP 8.2 or later.

Omnifood needs no framework. The core requires nothing but `symfony/http-client-contracts`, each
platform package `symfony/http-client`: two libraries that stand alone. It runs the same in plain
PHP, in a worker, in Laravel or Slim, and in Symfony, where a bundle does the wiring
([Symfony](symfony.md)).

## Plain PHP

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

With the restaurant's keys, the client is the real one and the script calls Uber:

```php
$http = Symfony\Component\HttpClient\HttpClient::create();
$options = ['client_id' => getenv('UBEREATS_CLIENT_ID') ?: null, 'client_secret' => getenv('UBEREATS_CLIENT_SECRET') ?: null, 'store_id' => getenv('UBEREATS_STORE_ID') ?: null];
```

That is all there is to it:

- a **factory** per platform package (`UberEatsPlatformFactory`, `DeliverooPlatformFactory`,
  `JustEatPlatformFactory`, `TheForkPlatformFactory`, `ZenchefPlatformFactory`), which takes the
  HTTP client to call with - the application's, a `MockHttpClient` in a test; with none given it
  makes its own (`HttpClient::create()`);
- the **registry**, built by hand from the factories and the platforms' options, by name
  ([configuration](configuration.md));
- the **platforms** it gives, each as what it does: `OrdersInterface`, `MenuInterface`,
  `StoreInterface`, `ReservationsInterface`, `NotifiableInterface`.

One platform needs no registry: `(new UberEatsPlatformFactory($http))->create($options)`.

No class of a framework is loaded on the way - a test of this package checks it in a process of
its own (`Tests/BareTest.php`), and so does `docker compose run --rm omnifood bare`
([harness](harness.md)).

## In a framework

- **Symfony**: `Omnifood\Bridge\Symfony\OmnifoodBundle` registers the factories on the
  application's `http_client`, builds the registry from `config/packages/omnifood.yaml` and makes
  each platform injectable by its name: see [Symfony](symfony.md). Its components
  (`symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`) are not required by
  this package: a Symfony application has them.
- **Any other**: build the `Registry` once, where the framework builds its services (a service
  provider, a container definition), as the script above does.
