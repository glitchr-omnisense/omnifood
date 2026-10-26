# Symfony

Omnifood runs without a framework ([installation](installation.md)); in a Symfony application
its bundle does the wiring. Register `Omnifood\Bridge\Symfony\OmnifoodBundle` (no Flex recipe):

```php
// config/bundles.php
return [
    // ...
    Omnifood\Bridge\Symfony\OmnifoodBundle::class => ['all' => true],
];
```

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

The entries are the registry's ([configuration](configuration.md)): a name (any), a `factory`
(`ubereats`, `deliveroo`, `justeat`, `thefork`, `zenchef`) and its `options`. Every `omnifood/*`
package installed registers its factory, on the application's `http_client`. What is autowired:

| Service | |
|---|---|
| `OrdersInterface $ubereats` | one platform by the argument's name (the configured name), as one thing it does |
| `MenuInterface`, `StoreInterface`, `ReservationsInterface`, `NotifiableInterface`, `PlatformInterface` | the same platform, typed as each interface |
| `Omnifood\Registry` | every configured platform by name (`get()`, `create()`, `orders()`, `reservations()`...) |
| `Omnifood\Validator` | what a menu breaks on a platform |

```php
use Omnifood\OrdersInterface;
use Omnifood\ReservationsInterface;

public function __construct(
    private OrdersInterface $ubereats,
    private ReservationsInterface $thefork,
) {}
```

A platform injected as something it does not do fails where it is injected (a `TypeError` at
construction): inject `PlatformInterface` or the `Registry` when in doubt.

Nothing is built when the container compiles: a platform is built the first time it is asked
for, and a key left empty (`%env(default::...)%` with the variable unset) only shows when a call
needs it (`InvalidConfigException`). A site with no keys yet still boots.

An application's own platform - a class implementing `PlatformFactoryInterface` - is registered
too, autoconfigured, and can be named as a `factory`.

## A webhook

```php
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

Answer fast and work later (Messenger): see [webhooks](webhooks.md).

The bundle's components - `symfony/config`, `symfony/dependency-injection`, `symfony/http-kernel` -
are not required by `omnisense/omnifood`: the application has them, and nothing of them is loaded
outside Symfony.
