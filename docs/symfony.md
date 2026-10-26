---
title: Symfony
order: 15
---

# Symfony

Omnifood runs without a framework ([Installation](installation.md)); in a Symfony application
its bundle does the wiring. The bundle's components - `symfony/config`,
`symfony/dependency-injection`, `symfony/http-kernel` (6.4, 7 or 8) - are not required by
`omnisense/omnifood`: the application has them, and nothing of them is loaded outside Symfony.

## Registering the bundle

There is no Flex recipe: add the bundle by hand.

```php
// config/bundles.php
return [
    // ...
    Omnifood\Bridge\Symfony\OmnifoodBundle::class => ['all' => true],
];
```

## Configuration

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:                     # by name: a factory and its options
        ubereats:
            factory: ubereats
            options:
                client_id: '%env(default::UBEREATS_CLIENT_ID)%'
                client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%'
                store_id: '%env(default::UBEREATS_STORE_ID)%'
        thefork:
            factory: thefork
            options:
                client_id: '%env(default::THEFORK_CLIENT_ID)%'
                client_secret: '%env(default::THEFORK_CLIENT_SECRET)%'
                restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%'
                webhook_token: '%env(default::THEFORK_WEBHOOK_TOKEN)%'
```

```sh
# .env.local, or bin/console secrets:set
UBEREATS_CLIENT_ID=...
UBEREATS_CLIENT_SECRET=...
```

`%env(default::NAME)%` resolves to `null` when the variable is not set; an option left `null` or
`''` is as if not given ([Configuration](configuration.md#options-left-empty)), so the
application boots before its accounts exist.

The whole tree, from the bundle's `configure()`:

| Key | Type | Default | |
|---|---|---|---|
| `omnifood.platforms` | map, keyed by the platform's name | `{}` | the restaurant's platforms |
| `omnifood.platforms.<name>.factory` | string, required, not empty | - | a factory's name: `ubereats`, `deliveroo`, `justeat`, `thefork`, `zenchef` ([platforms](index.md#platforms)), or an application's own factory's `getName()` |
| `omnifood.platforms.<name>.options` | any value (a map) | `[]` | handed as they are to the factory's `create()`: the platform's options ([Configuration](configuration.md#every-platforms-options)) |

The options are not validated when the container compiles - the tree accepts any value - and no
platform declares a required option: a key missing shows when a call needs it, as an
`InvalidConfigException` naming it. Two stores on the same platform are two entries with the
same factory.

## Services

The bundle knows the five platform packages (`OmnifoodBundle::FACTORIES`) and registers the
factory of each one installed, built on the application's `http_client` service (or with none,
when the application has no HTTP client service: the platform then makes its own).

| Service id | Class | Autowiring | |
|---|---|---|---|
| `Omnifood\Registry` | `Registry` | `Registry $registry` | every configured platform by name ([Concepts](concepts.md#registry)). Public. |
| `Omnifood\Validator` | `Validator` | `Validator $validator` | a menu checked against a platform's capabilities |
| `omnifood.platform.<name>` | `PlatformInterface` | `OrdersInterface $<name>`, `MenuInterface $<name>`, `StoreInterface $<name>`, `ReservationsInterface $<name>`, `NotifiableInterface $<name>`, `PlatformInterface $<name>` | one platform, built by `Registry::get('<name>')` the first time it is injected |
| `Omnifood\<Platform>\<Platform>PlatformFactory` | the platform's factory | by its class | tagged `omnifood.platform_factory` |

```php
use Omnifood\OrdersInterface;
use Omnifood\ReservationsInterface;

final class KitchenController
{
    public function __construct(
        private readonly OrdersInterface $ubereats,          // the "ubereats" platform, as what takes orders
        private readonly ReservationsInterface $thefork,     // the "thefork" platform
    ) {
    }
}
```

The argument's name selects the platform (Symfony's named autowiring aliases,
`registerAliasForArgument()`), the type says what it is used as. Every platform gets the six
aliases, whatever it implements: a platform injected as something it does not do fails where it
is injected, a `TypeError` when the service is built (`ReservationsInterface $ubereats`). Inject
`PlatformInterface`, or the `Registry` and its `orders()` / `reservations()` lists, when in doubt.

Nothing is built when the container compiles: a platform is built the first time it is asked
for. Keys typed in a back office rather than set in the environment:
`$registry->create('ubereats', ['client_secret' => $stored])`, a platform built afresh with those
options over the configured ones and not kept by the registry.

## An application's own platform

A class of the application that implements `Omnifood\PlatformFactoryInterface` (usually by
extending `Omnifood\PlatformFactory`) is autoconfigured: tagged `omnifood.platform_factory`, it
joins the registry, and its `getName()` can be named as a `factory`:

```yaml
omnifood:
    platforms:
        till:
            factory: my_till        # App\Food\MyTillPlatformFactory::getName()
```

([Writing a platform](providers.md))

## A webhook

```php
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\NotifiableInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhooks/ubereats', methods: ['POST'])]
public function ubereats(Request $request, NotifiableInterface $ubereats, MessageBusInterface $bus): Response
{
    try {
        $notification = $ubereats->notify($request->getContent(), $request->headers->all());
    } catch (InvalidSignatureException) {
        return new Response('', 401);
    }
    $bus->dispatch(new HandleNotification($notification->channel->value, $notification->id, $notification->reference));

    return new Response('', 200);
}
```

`HandleNotification` is the application's message. Answer fast and work later (Messenger): see
[webhooks](webhooks.md). TheFork's token travels in the URL: pass it among the headers
(`$request->headers->all() + ['token' => $request->query->get('token')]`, [webhooks](webhooks.md#what-each-platform-checks)).

The bundle registers no command, event, Twig function or route: the platforms are services, used
from the application's controllers, message handlers and commands.
