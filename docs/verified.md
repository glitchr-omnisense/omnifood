---
title: Verified, and not
order: 90
---

# Verified, and not

## What the tests prove

Run on 2026-10-26 in the [harness](harness.md), every package from its checkout, PHP 8.4.27,
PHPUnit 11.5.57: 89 tests, 479 assertions, green.

The core's tests cover the registry (built once, by capability, afresh with keys typed in a back
office, the unknown platform and factory, required options, empty options), the validator
(references, shared modifiers, modifiers as items, bounds, depth, names, prices, rates), `Hours`,
`Money`, `Token`, `Reservation::with()`, the Symfony bundle in a booted kernel
(`Tests/Bridge/OmnifoodBundleTest.php`: platforms injected by name as what they do, the packages
installed registered, a platform with no keys breaking nothing) and the plain-PHP run with no
framework class loaded (`Tests/BareTest.php`).

Each platform package tests, on recorded answers written from the platform's documentation: every
call it makes (method, path, query, body), the mapping of a recorded order or reservation, the
statuses, the menu's shape, the webhook's signature (Deliveroo's against its documented example),
the error mapping, the sandbox addresses, and that only what needs no key works without keys.

The capabilities table of [Concepts](concepts.md#capabilities) and the interfaces in the
[index](index.md#platforms) were probed on 2026-10-26; the examples of this manual were run in the
harness the same day.

## What is not verified

- That the platforms answer as recorded. No platform gives API keys without a partner agreement:
  Uber Eats, Deliveroo, Just Eat and TheFork are written from their public documentation, read in
  October 2026, and none was called for this manual. Uber Eats and Deliveroo have sandboxes
  (`sandbox: true`) to start from.
- Zenchef's API is documented on request only: `omnifood/zenchef` holds the credentials and a
  configured client, and refuses every reservation call.
- There is no conformance suite: the packages share their rules by convention. The defects
  found while writing these manuals (a 2xx body that is not JSON read as an empty answer, Just
  Eat's webhooks unchecked with only an HMAC secret, TheFork's phone numbers, the factories' date
  options) are fixed since, each with its test in its package.
- The [Laravel](laravel.md) page was written from the registry's API, not run in a Laravel
  application.

## Responsibility

`omnisense/omnifood` and its `omnifood/*` platforms are independent, unofficial clients: none is
affiliated with, endorsed by or sponsored by the services it reaches. Each service keeps its own
terms (partner agreements, keys, rate limits, how orders and guests' data may be used), named with
a link in each platform's README. The MIT licence covers this code only and grants no right to any
service or to its data; whoever runs these packages complies with those terms.
