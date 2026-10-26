---
title: Configuration reference
order: 40
---

# Configuration reference

## The registry

```php
use Omnifood\Registry;
use Omnifood\TheFork\TheForkPlatformFactory;
use Omnifood\UberEats\UberEatsPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$registry = new Registry([new UberEatsPlatformFactory($http), new TheForkPlatformFactory($http)], [
    'ubereats' => ['factory' => 'ubereats', 'options' => [
        'client_id' => getenv('UBEREATS_CLIENT_ID') ?: null,
        'client_secret' => getenv('UBEREATS_CLIENT_SECRET') ?: null,
        'store_id' => getenv('UBEREATS_STORE_ID') ?: null,
    ]],
    'thefork' => ['factory' => 'thefork', 'options' => [
        'client_id' => getenv('THEFORK_CLIENT_ID') ?: null,
        'client_secret' => getenv('THEFORK_CLIENT_SECRET') ?: null,
        'restaurant_id' => getenv('THEFORK_RESTAURANT_ID') ?: null,
        'webhook_token' => getenv('THEFORK_WEBHOOK_TOKEN') ?: null,
    ]],
]);

$ubereats = $registry->get('ubereats');   // built once, the first time it is asked for
$registry->orders();                       // the platforms that take orders
$registry->reservations();                 // the ones that take reservations
```

Each entry is a name (any), a `factory` (the package's: `ubereats`, `deliveroo`, `justeat`,
`thefork`, `zenchef`) and its `options`. Two Uber Eats stores are two entries with the same
factory. In a Symfony application the same entries are written in YAML, under
`omnifood.platforms` ([Symfony](symfony.md#configuration)).

| Key | Type | Required | |
|---|---|---|---|
| `<name>.factory` | string | yes | a factory's `getName()`; one not given to the registry is an `InvalidConfigException` when the platform is asked for |
| `<name>.options` | array | no (`[]`) | the platform's options, below |

## Options left empty

An option given as `null` or `''` - an environment variable not set - is removed before the
defaults are applied: the factory's default stands. `false`, `0` and `[]` are kept as given.
Booleans are read with `FILTER_VALIDATE_BOOL`, so `'true'`, `'1'`, `'on'` and `'yes'` are true
and any other string is false: an environment variable can be passed as it is.

## Nothing breaks before a key is set

No platform declares a required option: a platform is built with none, and a key left empty
only shows when a call needs it, as an `InvalidConfigException` naming it. `capabilities()` and
the menu validator need no key. So a site boots before its accounts exist:

```php
use Omnifood\Exception\InvalidConfigException;

$registry->has('ubereats');   // declared
try {
    $registry->get('ubereats')->orders();
} catch (InvalidConfigException $e) {
    // declared, not configured: The "ubereats" platform needs: client_id, client_secret.
}
```

## Keys typed in a back office

```php
$platform = $registry->create('ubereats', [
    'client_id' => $settings->get('ubereats.client_id'),
    'client_secret' => $settings->get('ubereats.client_secret'),
]);
```

`create()` builds a platform afresh, the configured options with the given ones over them
(`array_replace`); it is not kept (`get()` still gives the configured one).

## Tokens

Uber Eats, Deliveroo and TheFork hand out client-credentials tokens: the platform asks for one
when a call needs it and keeps it, in that object, while it lives.

| | Lifetime | Asked for again | Kept by the site |
|---|---|---|---|
| Uber Eats | 30 days (`expires_in`) | 5 minutes before it dies | `access_token` and `token_expires_at` options: Uber allows 100 token requests an hour |
| Deliveroo | 5 minutes | 20 seconds before it dies | no option: each process asks for its own |
| TheFork | about 2 h 23 | 60 seconds before it dies | `access_token` and `token_expires_at` options: TheFork asks that no new token be requested before |

A site that runs many processes (workers, PHP-FPM) keeps the token where they all read it:

```php
use Omnifood\Model\Token;

$kept = $cache->get('ubereats.token');                      // an array, or null
$platform = $registry->create('ubereats', $kept ? [
    'access_token' => $kept['access_token'],
    'token_expires_at' => $kept['expires_at'] ?? null,
] : []);
$platform->orders();                                        // asks for a token only if none is held or it is dying
$cache->set('ubereats.token', $platform->token()->toArray());

// a scheduled job, before it dies:
if (null === $kept || Token::fromArray($kept)->isExpiring(2)) {
    $cache->set('ubereats.token', $platform->refresh()->toArray());
}
```

`token()` is the token held (`null` before the first call), `refresh()` asks for a new one now
(`RefreshableInterface`). `token_expires_at` is read by `new \DateTimeImmutable()`: ATOM, as
`Token::toArray()` writes it.

## Linking a merchant's account (OAuth)

Uber Eats implements `OAuthInterface`, for the merchant's consent to link a store to the
application (scope `eats.pos_provisioning`):

```php
$url = $ubereats->authorizationUrl('https://site.example/admin/ubereats/callback', $state);
// the merchant is sent there; back on the callback, with ?code=...&state=...:
$token = $ubereats->exchange($code, 'https://site.example/admin/ubereats/callback');
```

`authorizationUrl()` is `https://auth.uber.com/oauth/v2/authorize` with `client_id`,
`response_type=code`, the redirect URI, the scopes (`eats.pos_provisioning` unless others are
given) and the state; checking the state on the way back is the application's job. The token
`exchange()` gives (with a refresh token) is for the provisioning calls, not for the orders.

## Every platform's options

Read from each factory's `populate()` and `build()`. Every option is optional when the platform
is built; "needed by" says which calls fail without it.

### ubereats

| Option | Type | Default | Needed by | |
|---|---|---|---|---|
| `client_id` | string | - | every call (the token), `authorizationUrl()` | the Developer Dashboard application's |
| `client_secret` | string | - | every call, `notify()` | the application's secret; also the webhooks' signing key |
| `store_id` | string | - | `orders()`, the menu, the store | the store's id at Uber |
| `scopes` | list, or a string split on spaces and commas | `eats.store`, `eats.order`, `eats.store.orders.read`, `eats.store.status.write` | | the client-credentials token's scopes |
| `access_token` | string | - | | a token kept by the site |
| `token_expires_at` | string (a date) | - | | when it dies |
| `language` | string | `en_us` | | the key of the menu's translations |
| `tax_inclusive` | bool | `true` | | `true`: items carry `vat_rate_percentage` (prices include VAT); `false`: `tax_rate` |
| `sandbox` | bool | `false` | | `https://test-api.uber.com` and `https://sandbox-login.uber.com/oauth/v2/token` |
| `base_uri` | string | `https://api.uber.com` | | another API address |
| `token_url` | string | `https://auth.uber.com/oauth/v2/token` | | another token endpoint |

### deliveroo

| Option | Type | Default | Needed by | |
|---|---|---|---|---|
| `client_id`, `client_secret` | string | - | every call (the token, HTTP Basic) | the Developer Portal application's |
| `brand_id` | string | - | `orders()`, the menu, the site | from Deliveroo's integration team |
| `site_id` | string | - | `orders()`, the menu, the site | the restaurant's location id |
| `menu_id` | string | - | `pushMenu()`, `setAvailability()` | the menu written to |
| `webhook_secret` | string | - | `notify()` | from the account manager |
| `sandbox` | bool | `false` | | `https://api-sandbox.developers.deliveroo.com`, `https://auth-sandbox.developers.deliveroo.com/oauth2/token` |
| `tablet` | bool | `false` | | the site has a Deliveroo tablet: `accept()` and `deny()` send the till's sync status |
| `language` | string | `fr` | | the language key of the menu's texts |
| `mealtime_image` | string (URL) | - | | the menu's picture (Deliveroo requires one), else the first item's photo |
| `base_uri` | string | `https://api.developers.deliveroo.com` | | |
| `token_url` | string | `https://auth.developers.deliveroo.com/oauth2/token` | | |

### justeat

| Option | Type | Default | Needed by | |
|---|---|---|---|---|
| `api_key` | string | - | every call | JET Connect's key for the brand (`X-Flyt-Api-Key`) |
| `restaurant` | string | - | the menu, the restaurant | the restaurant's reference at JET Connect (`posLocationId`) |
| `webhook_key` | string | - | `notify()` (or `webhook_secret`) | the key given to JET for its webhooks, sent back as `Authorization` |
| `webhook_secret` | string | - | `notify()` (or `webhook_key`) | the HMAC secret given to JET (`X-JET-Connect-Hash`) |
| `currency` | string | `GBP` | | the orders' amounts come without one |
| `timezone` | string | `Europe/London` | | the restaurant's: service times, the end of a pause; an unknown zone fails when the platform is built (PHP's `\Exception`) |
| `menu_type` | string | `DELIVERY` | | or `COLLECTION` (upper-cased) |
| `standard_vat_rate` | float | `20.0` | | at or above: `STANDARD_RATE`; above 0 and under: `REDUCED_RATE`; 0: `NO_TAX` |
| `base_uri` | string | `https://api.flytplatform.com` | | |

### thefork

| Option | Type | Default | Needed by | |
|---|---|---|---|---|
| `client_id`, `client_secret` | string | - | every call (the token) | sent by TheFork |
| `restaurant_id` | string | - | `reservations()`, `createReservation()`, `pushAvailability()` | the restaurant's UUID at TheFork |
| `webhook_token` | string | - | `notify()` | the token put in the webhook URL given to TheFork |
| `access_token` | string | - | | a token kept by the site |
| `token_expires_at` | string (a date) | - | | when it dies |
| `locale` | string | `fr_FR` | | a guest's, when the reservation does not say |
| `civility` | string | `mx` | | a guest's, required by TheFork, when `raw['civility']` does not say |
| `with_customers` | bool | `true` | | read each reservation's customer (name, phone, allergies): one call more each |
| `base_uri` | string | `https://api.thefork.io/manager` | | |
| `token_url` | string | `https://auth.thefork.io/oauth/token` | | |

### zenchef

| Option | Type | Default | Needed by | |
|---|---|---|---|---|
| `token` | string | - | `api()->request()` | Settings > Partners in Zenchef: "Zenchef token" |
| `restaurant_id` | string | - | `api()->request()` | Settings > Partners |
| `base_uri` | string | - | `api()->request()` | from the documentation Zenchef sends on request |
| `headers` | map | `{}` | `api()->request()` | from it too; `{token}` and `{restaurant_id}` in a value stand for the two above |

Every reservation call of Zenchef is refused whatever is configured; `api()` is the configured
client ([its manual](https://github.com/glitchr-omnisense/omnifood-zenchef)).
