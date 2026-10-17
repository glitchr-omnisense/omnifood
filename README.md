# omniscient/omnifood

One contract for the restaurant platforms - the delivery orders (Uber Eats, Deliveroo, Just Eat)
and the table reservations (TheFork, Zenchef) - the Omnibus of the kitchen, beside
omniscient/omnitrade (payments), omniscient/omnibus (shipping) and omniscient/omnipost
(publishing).

Published as `omniscient/omnifood` (formerly `glitchr/omnifood`, which this package replaces: a
`composer.json` that still requires the old name keeps resolving to this package and never installs
both). `omniscient/` is the vendor of every family's core, the providers keep `omnifood/`, the
namespace `Omnifood\` and the GitHub repositories do not change.

```php
// A webhook: its raw body and its headers checked, read, the same shape whatever the platform.
$notification = $ubereats->notify(file_get_contents('php://input'), getallheaders());
$order = $notification->order ?? $ubereats->order($notification->reference);

$ubereats->accept($order->reference, new \DateTimeImmutable('+20 minutes'));   // within 11.5 minutes
$ubereats->ready($order->reference);                                            // the courier may come

$deliveroo->pushMenu($menu);                       // checked first: InvalidMenuException, nothing sent
$deliveroo->setAvailability('gyoza', false);       // out of stock
$justeat->pause(new \DateTimeImmutable('+30 minutes'));

foreach ($thefork->reservations(new \DateTimeImmutable('today'), new \DateTimeImmutable('tomorrow')) as $reservation) {
    echo $reservation->date->format('H:i'), ' ', $reservation->covers, ' ', $reservation->guest->name(), "\n";
}
```

**Capabilities, not one big interface.** The platforms do different things: a platform implements
what it offers - `OrdersInterface`, `MenuInterface`, `StoreInterface`, `ReservationsInterface`,
`NotifiableInterface` - and `Auth\RefreshableInterface` / `Auth\OAuthInterface` for its tokens.
What a platform offers but its public documentation does not show throws `NotSupportedException`:
nothing is guessed.

**One model.** `Order` (its `Line`s and their `Modifier`s, `Customer`, `Courier`, `Money` in minor
units, `OrderType`, `OrderStatus`), `Menu\{Menu, Category, Item, ModifierGroup, Modifier}` (prices,
VAT rates, the EU-14 `Allergen`s, the restaurant's own references), `Reservation` (`Guest`,
`ReservationStatus`), `Slot`, `Hours`, `StoreStatus`, `Notification`, `Token`. Each keeps the
platform's own answer in `$raw`.

**Checked before it is sent.** `capabilities()` says what a platform takes (its order types, how long
it gives to accept, how deep modifiers nest, allergens, photos, VAT rates, names' lengths...); the
`Validator` lists what a menu breaks, by path, for a back office to show; `pushMenu()` runs it first.

**The keys stay with the restaurant.** A platform is built from options - a client id, a secret, a
store id - and calls the platform directly through the application's HTTP client.

**No framework.** The core requires nothing but `symfony/http-client-contracts`, each platform
package `symfony/http-client`: Omnifood runs in plain PHP, and in any framework. Its Symfony bundle
is a bridge (`Bridge/Symfony`), whose components are not required; it never depends on omnibase.

| Package | Platform | Capabilities |
|---|---|---|
| `omnifood/ubereats` | Uber Eats (Marketplace APIs) | orders, menu, store, webhooks, token, OAuth (store linking) |
| `omnifood/deliveroo` | Deliveroo (Partner Platform) | orders, menu, store, webhooks, token |
| `omnifood/justeat` | Just Eat Takeaway.com (JET Connect) | orders (pushed), menu, store, webhooks |
| `omnifood/thefork` | TheFork (B2B API) | reservations, webhooks, token |
| `omnifood/zenchef` | Zenchef | credentials only: the API is documented on request |

None of these platforms gives credentials without a partner agreement: every package is written
from the platform's public documentation and tested against recorded answers (`MockHttpClient`),
**not verified against the live APIs**. Each README says what to ask for.

## Install

```sh
composer require omniscient/omnifood omnifood/ubereats omnifood/deliveroo omnifood/justeat omnifood/thefork
```

## Plain PHP

```php
use Omnifood\Registry;
use Omnifood\TheFork\TheForkPlatformFactory;
use Omnifood\UberEats\UberEatsPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();   // or the application's client; a MockHttpClient in a test
$registry = new Registry([new UberEatsPlatformFactory($http), new TheForkPlatformFactory($http)], [
    'ubereats' => ['factory' => 'ubereats', 'options' => ['client_id' => '...', 'client_secret' => '...', 'store_id' => '...']],
    'thefork' => ['factory' => 'thefork', 'options' => ['client_id' => '...', 'client_secret' => '...', 'restaurant_id' => '...']],
]);
$ubereats = $registry->get('ubereats');
$registry->orders();        // the platforms that take orders
$registry->reservations();  // the ones that take reservations
```

No bundle, no container: a factory per platform package, the registry built by hand.
[docs/installation.md](docs/installation.md) opens on a whole script that runs as it is, on a
recorded answer. A platform is built the first time it is asked for, and a key left empty only
shows when a call needs it (`InvalidConfigException`) - `capabilities()` needs none. Keys typed in
a back office rather than kept in the environment:
`$registry->create('ubereats', ['client_id' => ..., 'client_secret' => ...])`.

## Symfony

`Omnifood\Bridge\Symfony\OmnifoodBundle` does that wiring in a Symfony application: every
`omnifood/*` package installed registered on the application's `http_client`, `Omnifood\Registry`
and `Omnifood\Validator` autowired, each configured platform injectable by its name, as what it
does. Its components (`symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel`) are
not required by this package. See [docs/symfony.md](docs/symfony.md).

```yaml
omnifood:
    platforms:
        ubereats:  { factory: ubereats, options: { client_id: '%env(default::UBEREATS_CLIENT_ID)%', client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%', store_id: '%env(default::UBEREATS_STORE_ID)%' } }
        deliveroo: { factory: deliveroo, options: { client_id: '%env(default::DELIVEROO_CLIENT_ID)%', client_secret: '%env(default::DELIVEROO_CLIENT_SECRET)%', brand_id: '%env(default::DELIVEROO_BRAND_ID)%', site_id: '%env(default::DELIVEROO_SITE_ID)%', menu_id: '%env(default::DELIVEROO_MENU_ID)%', webhook_secret: '%env(default::DELIVEROO_WEBHOOK_SECRET)%' } }
        thefork:   { factory: thefork, options: { client_id: '%env(default::THEFORK_CLIENT_ID)%', client_secret: '%env(default::THEFORK_CLIENT_SECRET)%', restaurant_id: '%env(default::THEFORK_RESTAURANT_ID)%', webhook_token: '%env(default::THEFORK_WEBHOOK_TOKEN)%' } }
```

```php
public function __construct(OrdersInterface $ubereats, MenuInterface $deliveroo, ReservationsInterface $thefork, Registry $omnifood, Validator $validator) {}
```

An application's own `PlatformFactoryInterface` is registered too (autoconfigured).

## Docker: every platform with your keys

`docker/` runs this package with every `omnifood/*` platform - from GitHub, or from the checkouts
beside this one when `OMNIFOOD_PLUGINS=../..` is set - and a console that exercises them with the
keys in `docker/.env` (copy `.env.dist`):

```sh
cd docker && cp .env.dist .env
docker compose run --rm omnifood platforms                       # installed, configured, what each does
docker compose run --rm omnifood capabilities ubereats           # no key needed
docker compose run --rm omnifood menu:push deliveroo /omnifood/core/docker/menu.example.json --validate
docker compose run --rm omnifood orders deliveroo --since "-2 hours"
docker compose run --rm omnifood order ubereats <id>
docker compose run --rm omnifood accept ubereats <id> --ready-at "+20 minutes"
docker compose run --rm omnifood deny ubereats <id> --reason too_busy
docker compose run --rm omnifood ready deliveroo <id>
docker compose run --rm omnifood store:status ubereats
docker compose run --rm omnifood reservations thefork --from today --to "+7 days"
docker compose run --rm omnifood notify ubereats /omnifood/core/docker/body.json -H "X-Uber-Signature: ..."
docker compose run --rm omnifood refresh thefork
docker compose run --rm omnifood bare                            # plain PHP: no bundle, no container, and what PHP loaded
docker compose run --rm omnifood test                            # every package's tests
```

More in [docs/](docs/index.md).

## Responsibility

`omniscient/omnifood` and its `omnifood/*` providers are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (partner agreements and approvals, keys, rate limits, how orders and customer data may be
used), named with a link in the "Responsibility" section of the README of each provider that reaches
one. The MIT licence covers this code only and grants no right to any service or to its data.

Whoever installs and runs these packages is responsible for complying with those terms, for
obtaining whatever they require, and for any use made of what they return. The authors publish them
free of charge, make no commercial use of them, and accept no liability for how they are used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
