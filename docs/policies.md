---
title: Usage policies and rate limits
order: 55
---

# Usage policies and rate limits

Omnifood is built on [omnisense/contracts](https://github.com/glitchr-omnisense/contracts). Each
platform package declares its **usage policy** as data: rate limits, rights, requirements, and
where they were read. The file is `policy.json` in the package, named by `extra.omnisense.policy`
in its `composer.json`, in the schema of omnisense/contracts
(`resources/usage-policy.schema.json`); the platform gives it (`usagePolicy()`,
`DeclaresUsagePolicyInterface`). Run `vendor/bin/omnisense-policies` to print every installed one.

Every platform here is **contract-only**: its API is open to the partners it signs, the keys come
from it, and its terms are the partner contract. The rights are declared `contract`, and a limit
is declared only where the platform publishes a figure.

| Package | Rate limits declared | The real rule, in the policy's notes |
|---|---|---|
| omnifood/ubereats | 100 token requests an hour (`refresh`) | the Marketplace APIs' scopes need Uber's written approval; Uber may limit calls at any time and publishes no figure for orders, store and menu |
| omnifood/deliveroo | 1 menu upload a minute (`pushMenu`) | an order's status 5 a minute per order, an order read 1 a second per order (per order: not counted by the guard) |
| omnifood/justeat | unknown (`null`) | JET Connect publishes no limit; the API key comes from JET |
| omnifood/thefork | 200 a minute, 10,000 a day | the Partners API Terms of Use's defaults, negotiable; caching needs TheFork's approval, no data forwarded |
| omnifood/zenchef | 100 a minute, concurrency 1 | for the calls answered in less than a second, the slower ones one after the other |

## Reading and tightening a policy

```php
$registry = new Registry($factories, [
    'ubereats' => [
        'factory' => 'ubereats',
        'options' => ['client_id' => '...', 'client_secret' => '...', 'store_id' => '...'],
        'policy' => ['rateLimits' => [['max' => 60, 'window' => 'minute', 'operations' => ['orders']]]],
        'usage' => ['commercial' => true, 'contract' => true],
    ],
], $sharedLimiter);

$registry->policy('ubereats');      // Uber Eats', tightened by the site's
$registry->policies();              // every platform's: a "services we use" page
```

A site may only tighten a policy: a looser limit is an `InvalidPolicyException` from
omnisense/contracts. An operation is the contract's method name: `order`, `orders`, `accept`,
`deny`, `ready`, `cancel`, `pushMenu`, `setAvailability`, `status`, `pause`, `resume`, `setHours`,
`reservations`, `reservation`, `createReservation`, `updateReservation`, `cancelReservation`,
`setStatus`, `pushAvailability` (one request per slot), and `refresh` for a token asked on purpose.

## The guard

`Registry::get()`, `orders()`, `menus()`... give the platforms as before. `guardedOrders($name)`,
`guardedMenu($name)`, `guardedStore($name)` and `guardedReservations($name)` give one behind its
policy (`Omnifood\Policy\Guarded*`): before a call is sent, each limit counting its operation is
checked, and a limit reached refuses it with `UsageLimitReachedException` - nothing sent. The call
is counted after, answered or failed. A webhook (`notify()`) is never guarded. `guard($name)` is
the `PolicyGuard` itself (`remaining()`, `cacheTtl()`).

The limiter counts in memory by default. An application served by many processes gives the
registry a shared one, its third argument (in Symfony, `omnifood.limiter`): any
`Omnisense\Contracts\Policy\RateLimiterInterface`.

## A 429, and a write never sent twice

Guarded or not, each platform's `Api` honours the waits the platform asks for:

- **A 429** is a `RateLimitedException`: a `ProviderException` (status 429, so existing catches
  still hold it) with `retryAfter` in seconds when the platform said (`Retry-After`, or the
  `RateLimit` field).
- **The next calls** to that platform wait as long: refused with `UsageLimitReachedException`
  before anything is sent, in every process when the limiter is shared.
- **Nothing retries a call**: not the guard, not the wait, not the `Api`, and not the HTTP client.
  Symfony's `RetryableHttpClient` (`framework.http_client`'s `retry_failed`) retries a 429, 423,
  425, 502 and 503 whatever the method; each `Api` sends with its retries off
  (`Omnifood\Http\NoRetry`: `max_retries: 0`, through the decorators), and so does every
  `PlatformFactory`, so an application's own platform is covered too. Since omnisense/contracts
  1568f2c, `Omnifood\Http\NoRetry` is the kernel's (`Omnisense\Contracts\Http\NoRetry`); with an
  older kernel, omnifood's own code still turns the retries off. A `MockHttpClient` is kept as
  given (not copied: `getRequestsCount()` still counts).

No platform offers an idempotency key on its writes - Uber Eats' `accept_pos_order`, Deliveroo's
order status (it "should be called only once per order"), JET Connect's callbacks, TheFork's
reservation creation, Zenchef's - so Omnifood sends each once. A limit reached or a wait refuses
the call before anything is sent; a 429 is the platform's refusal (TheFork's gateway answers it
before the request reaches the endpoint). A 5xx or a timeout may come after the write ran: read
the order or the reservation before sending it again.

```php
try {
    $registry->guardedOrders('ubereats')->accept($ref, new \DateTimeImmutable('+20 minutes'));
} catch (RateLimitedException|UsageLimitReachedException $e) {
    // Nothing was accepted: try again in $e->retryAfter seconds, within the acceptance delay.
}
```
