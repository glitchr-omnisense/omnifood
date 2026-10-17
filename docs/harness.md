# Harness

`docker/` in this package runs it with every `omnifood/*` platform installed and a console to call
them with real keys.

```sh
cd vendor/omniscient/omnifood/docker   # or the checkout's docker/
cp .env.dist .env                   # fill in the keys at hand
docker compose run --rm omnifood platforms
```

The platforms come from GitHub (branch 1.x), or from the checkouts beside the core when
`OMNIFOOD_PLUGINS=../..` is set in `.env` (the workspace layout).

| Command | Does |
|---|---|
| `platforms` | installed, configured (which variables are missing), what each does |
| `capabilities <platform>` | what it takes, as JSON (no key) |
| `menu:push <platform> <file> [--validate]` | a menu from JSON (`menu.example.json`): checked, or sent |
| `orders <platform> [--since]`, `order <platform> <id>` | the orders, as JSON |
| `accept`, `deny --reason --note`, `ready` | the order's cycle |
| `store:status <platform>` | open or not |
| `reservations <platform> [--from --to]` | the reservations, as JSON |
| `notify <platform> <body file> -H "Name: value"` | a webhook checked and read |
| `refresh <platform>`, `authorize <platform> <redirect-uri>` | tokens |
| `bare` | plain PHP: the registry built by hand, an order read from a recorded answer, what PHP loaded |
| `test` | every package's tests |

## Bare: no bundle, no container

The console above is a `symfony/console` application over a registry built by hand; `bare` is
less still - one PHP script, `docker/harness/bin/bare`, that requires the autoloader and nothing
else. It builds the `Registry` from the platform packages installed, asks each what it takes,
reads an Uber Eats order from a recorded answer (`docker/harness/recorded/`), then lists what PHP
loaded and exits 1 if a class of a framework is among it (`Symfony\Component\DependencyInjection`,
`Config`, `HttpKernel`, `HttpFoundation`, a bundle, Doctrine, Twig):

```
$ docker compose run --rm omnifood bare
Omnifood in bare PHP: the registry built by hand, no bundle, no container.

  ubereats   orders menu store notify         accepts within 690 s
  deliveroo  orders menu store notify         accepts within 600 s
  justeat    orders menu store notify
  thefork    reservations notify
  zenchef    reservations notify

Uber Eats, order o-1 from a recorded answer:
  #A1B2C delivery new, 2 x Shoyu ramen, 31.90 EUR, for Aiko T., ready at 2026-10-04T12:15:00+00:00

Loaded from Symfony: Symfony\Component\HttpClient, Symfony\Contracts\HttpClient, Symfony\Contracts\Service
Classes of a framework (DependencyInjection, Config, HttpKernel, HttpFoundation, a bundle, Doctrine, Twig): none
```

`bare --json` prints the same whole, every class and file loaded: `Tests/BareTest.php` runs it
in a process of its own and checks the list.
