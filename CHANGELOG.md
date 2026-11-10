# Changelog

## [Unreleased]

### Changed

- `Http\NoRetry::of()` is omnisense/contracts' `Http\NoRetry::of()` when the kernel has it (from
  contracts 1568f2c, which brings the shared protection); with an older kernel in a site's vendor,
  omnifood's former code runs. Same name, same signature.
- `PlatformFactory` keeps the HTTP client it is given with its retries off (`Http\NoRetry`): an
  application's own platform, whose Api does not call NoRetry, never sends an order's acceptance
  twice through Symfony's `RetryableHttpClient` either. `$http` stays a protected readonly property.

### Fixed

- `Http\NoRetry` kept a `MockHttpClient` as a copy (it takes any option), whose
  `getRequestsCount()` a test no longer saw: it is kept as given now, in the fallback too.
  `Tests/PolicyTest`: an acceptance answered 503 then 200 through Traceable over Retryable sent
  once by the factory alone, a `MockHttpClient` kept as given.

### Added

- omnisense/omnifood is built on omnisense/contracts, the kernel of the omni families, with its
  usage policies; the public API unchanged. `Config` and `Http\Redaction` are thin subclasses of the
  kernel's (prefix `omnifood`), `OmnifoodException` extends its `ExceptionInterface`,
  `InvalidConfigException` its `InvalidConfigException` (an `\InvalidArgumentException`, a
  `\LogicException` as before).
- Usage policies ([docs/policies.md](docs/policies.md)): each platform declares its policy
  (`policy.json`, `DeclaresUsagePolicyInterface`); `Registry::policy()`, `policies()`, `guard()`,
  and `guardedOrders()`, `guardedMenu()`, `guardedStore()`, `guardedReservations()`
  (`Policy\Guarded*`), each call refused before it is sent once a limit is reached
  (`Exception\UsageLimitReachedException`); `get()` and the typed lists unguarded as before. `policy`
  and `usage` beside a platform's options, a shared `RateLimiterInterface` as the registry's third
  argument; the bundle takes `policy`, `usage` and `limiter`.
- `Http\Throttle`: a platform's `Api` honours the waits it asks for - no call while one lasts, a 429 an
  `Exception\RateLimitedException` (a `ProviderException`, status 429, with `retryAfter`) - and
  `PlatformFactory` puts the platform's Backoff in the Config (`Config::backoff()`).
- `Http\NoRetry`: each `Api` sends with the HTTP client's retries off, so that Symfony's
  `RetryableHttpClient` (`retry_failed`) never sends an order's acceptance, a menu or a booking twice.
- `Testing\BareTestCase`, the kernel's bare check for an omnifood package; `Tests/LoadAllTest`.
- PHPStan at level max (`phpstan.neon`), `phpstan-baseline.neon` what was there when it came in;
  the harness's `phpstan` command, its contracts checkout (`OMNISENSE_CONTRACTS`), Docker's default
  bridge and its entrypoint named (another family's image tagged serves).

### Changed

- The Symfony bundle is the only class needing a framework: its file throws a `LogicException`
  naming symfony/http-kernel, symfony/dependency-injection and symfony/config when they are missing.

- `Http\Redaction`: what the platforms call at their catch of the HTTP client's exception, so
  that a `ProviderException` carries none of the request's secrets - its message with the key's
  parameters, the URL's user part and the request's secrets masked as `***` (`text()`), as
  previous a copy of the client's exception, the same class and code (`copy()`), and no trace
  of the chain keeping its arguments (`withoutArguments()`).

### Fixed

- `PlatformFactory::create()` throws what fails without arguments in its trace: with
  `zend.exception_ignore_args` off, an `InvalidConfigException` (a missing option) kept the
  options given, credentials included, in the arguments of `create()`.
- composer.json requires `ext-mbstring`, which the package calls (`mb_*` functions): a PHP built without it failed with an undefined function.
