---
title: Errors
order: 50
---

# Errors

Every exception Omnifood throws on purpose implements `Omnifood\Exception\OmnifoodException` (a
`\Throwable`):

| Exception | Extends | When |
|---|---|---|
| `InvalidConfigException` | `\LogicException` | a platform not configured, a factory not given to the registry, a required option missing, a key a call needs and finds empty (`The "ubereats" platform needs: store_id.`) |
| `NotSupportedException` | `\LogicException` | the platform does not do that, or its public documentation does not show how: thrown before anything is sent (`The "justeat" platform does not read an order (...)`) |
| `InvalidMenuException` | `\InvalidArgumentException` | `pushMenu()` found violations: nothing was sent. `platform`, `violations` (`list<Violation>`); the message joins them: `[deliveroo] path: message; path: message` |
| `InvalidSignatureException` | `\RuntimeException` | a webhook whose signature, key or token does not hold, or whose body is not JSON. `platform`. Answer 401, handle nothing |
| `ProviderException` | `\RuntimeException` | the platform refused, or could not be reached |
| `UnauthorizedException` | `ProviderException` | the platform answered 401 or 403: the keys refused, a scope missing, the account to connect again |

Outside the family's exceptions: `Hours`' constructor throws `\InvalidArgumentException` for a
day, a time or a range it cannot read ([Concepts](concepts.md#hours)); TheFork's
`updateReservation()` a `\InvalidArgumentException` for a reservation without a reference, and
`pushAvailability()` for an entry that is not a `Slot`, `createReservation()` for a national
phone number in no country it knows ([omnifood/thefork](https://github.com/glitchr-omnisense/omnifood-thefork)).

An option a factory cannot read as a date or a time zone - Just Eat's `timezone`, Uber Eats' and
TheFork's `token_expires_at` - is an `InvalidConfigException` (PHP's own exception as its previous).

## ProviderException

```php
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\UnauthorizedException;

try {
    $platform->accept($ref);
} catch (UnauthorizedException $e) {
    // the keys: alert someone, do not retry
} catch (ProviderException $e) {
    $e->platform;       // "ubereats", "deliveroo"...
    $e->status;         // the HTTP status, null when the platform could not be reached
    $e->providerCode;   // the platform's own error code, when it gave one; else null
    $e->getMessage();   // "[ubereats] <the platform's message>"
    $e->getPrevious();  // a copy of the HTTP client's exception, for a network failure
}
```

What each package turns into which exception (checked on recorded answers on 2026-10-26 for Uber
Eats; the four others follow the same code):

| The platform answers | Exception | Message |
|---|---|---|
| 401, 403 | `UnauthorizedException` | the platform's message: `[ubereats] Invalid token.` |
| 429 | `ProviderException` | `[ubereats] Too many calls, try again later: Slow down.` |
| another 4xx or 5xx | `ProviderException` | its message, else `[ubereats] HTTP 500.` |
| a network failure | `ProviderException`, status `null` | `[ubereats] Uber could not be reached: Connection refused` |
| a token answer without a token | `ProviderException` | `[ubereats] No token in Uber's answer.` |
| a 2xx whose body is not JSON (a proxy's page) | `ProviderException`, its status | `[ubereats] An answer that is not JSON (HTTP 200).` |

The message and the code are read from each platform's error shape: Uber's `{code, message}`,
Deliveroo's `{error: {code, message}}`, JET's `{success: false, error_message}`, `{message}` or
`{fault: {errors: [...]}}`, TheFork's `{data: {code}, error, statusCode}` or `{message}`, the
OAuth endpoints' `{error, error_description}`. A JET answer with `"success": false` is a
`ProviderException` whatever its status; so is a 2xx answer whose body is not JSON, on every
platform, where it was read as an empty answer.

## No secret in a failure

What a platform throws keeps the request's secrets out, for a log that writes the whole chain,
arguments included (`zend.exception_ignore_args` off). The HTTP client's message quotes the URL
called: in the `ProviderException`'s message its key parameters, a password in its user part and every secret
of the request (a key, a password, a bearer or basic credential) are masked as `***`
(`Omnifood\Http\Redaction::text()`). The previous exception is a copy of the client's
(`Redaction::copy()`: the same class and code, that message, its own previous copied the same
way, without the response or request a client's exception holds), and no trace of the chain keeps
its arguments, where the options, headers and body stood (`Redaction::withoutArguments()`).

## What to catch where

| Where | Catch |
|---|---|
| a webhook controller | `InvalidSignatureException`: answer 401; `InvalidConfigException`: the secret is not configured, answer 500 so that the platform retries once it is |
| accepting an order | `ProviderException`: the delay runs (`acceptanceDelay`), retry soon, then tell the kitchen; `UnauthorizedException`: alert someone |
| pushing a menu | `InvalidMenuException`: show its `violations` in the back office; `ProviderException` |
| a feature a platform may lack | check the interface (`instanceof`) and `capabilities()` first; `NotSupportedException` is a programming error |
| building the registry, a deploy | `InvalidConfigException`: a configuration error, to fix, not to retry |

A platform's message and code are the platform's, untranslated: show the restaurant a message of
the site's, keep the platform's for the logs.
