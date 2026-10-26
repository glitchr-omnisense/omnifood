---
title: Writing a platform
order: 80
---

# Writing a platform

A platform package - DoorDash, Wolt, a till's own API - is a factory and a platform class, in a
package of its own (`omnifood/<slug>`, namespace `Omnifood\<Platform>\`), requiring
`omnisense/omnifood` and `symfony/http-client`. The five packages share one layout: a
`<Platform>PlatformFactory`, a `<Platform>Platform`, an `Api` that does the HTTP and the errors.

## The factory

```php
namespace Omnifood\Wolt;

use Omnifood\Config;
use Omnifood\PlatformFactory;
use Omnifood\PlatformInterface;

final class WoltPlatformFactory extends PlatformFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults([
            'omnifood.factory_name' => 'wolt',
            'omnifood.required_options' => [],      // check keys when a call needs them
            'api_key' => null,
            'venue_id' => null,
            'base_uri' => Api::BASE_URI,
        ]);
    }

    protected function build(Config $config): PlatformInterface
    {
        return new WoltPlatform(
            new Api($config['api_key'] ? (string) $config['api_key'] : null, (string) $config['base_uri'], $this->http),
            $config['venue_id'] ? (string) $config['venue_id'] : null,
        );
    }
}
```

- `populate()` names the factory (snake_case, the name it is configured with) and gives every
  option its default. Options given as `null` or `''` were removed before: the defaults stand.
- Keep `omnifood.required_options` empty, as the five packages do, and throw
  `InvalidConfigException('The "wolt" platform needs: api_key.')` when a call finds the key empty:
  then `capabilities()` and the menu validator work with no key, and a site boots before its
  accounts exist.
- `$this->http` is the HTTP client given to the factory, or `null`: the `Api` then calls
  `HttpClient::create()`.
- Build nothing that calls: no token, no request in `build()`.

## The platform

Implement `PlatformInterface` and the capability interfaces the platform offers. A method the
interface requires and the platform does not document throws
`NotSupportedException::operation($this->getName(), 'cancel an order (why)')`, before any call.

- `capabilities()`: what the platform takes, without a key ([Concepts](concepts.md#capabilities)).
  The validator checks menus against it, so the limits there must be the platform's.
- `pushMenu()`: run `Validator::validate($menu, $this->capabilities())`, add the platform's own
  checks as `Violation`s, throw `InvalidMenuException` before sending.
- Orders and reservations: map the platform's statuses to `OrderStatus` / `ReservationStatus`
  (`UNKNOWN` for anything else), amounts to `Money` in minor units, keep the answer in `$raw`.
- `notify()`: lower-case the header names, take the first value of a list, check the signature
  with `hash_equals()` before decoding, throw `InvalidSignatureException` for a bad signature or a
  body that is not JSON, and give `Notification::$id` a value that is the same on a retry.
- Tokens: implement `Auth\RefreshableInterface` when the platform hands out tokens, and take an
  `access_token` / `token_expires_at` option when the platform limits how often one may be asked
  for.

## The Api

One `send()` for every call: the HTTP client's exceptions as
`ProviderException($platform, '... could not be reached: '.$e->getMessage(), null, null, $e)`,
401 and 403 as `UnauthorizedException`, any other status of 400 or more as `ProviderException`
with the platform's message and code. A 2xx body that is not JSON should be a `ProviderException`
too - the five packages read it as an empty answer today ([Errors](errors.md#providerexception)).

## Registering it

- Plain PHP: give the factory to the `Registry`.
- Symfony: a class implementing `PlatformFactoryInterface` is autoconfigured (tag
  `omnifood.platform_factory`); a published package is added to `OmnifoodBundle::FACTORIES` so
  that it is registered with the application's `http_client`.
- The harness: a line in `docker/harness/plugins.php` (slug, tests namespace, factory class) and its
  variables in `docker/harness/config/platforms.php` and `.env.dist`.

## Tests

There is no conformance suite in the core yet. Each package tests itself on recorded answers with
a `MockHttpClient` (`Tests/<Platform>PlatformTest.php`): every call's method, path, query and body,
the mapping of a recorded order, the statuses, the menu's shape, the webhook's check, the errors
(401, 429, 500, a network failure) and that nothing but `capabilities()` and the validator works
without keys.
