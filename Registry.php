<?php

namespace Omnifood;

use Omnifood\Exception\InvalidConfigException;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\Policy\GuardedMenu;
use Omnifood\Policy\GuardedOrders;
use Omnifood\Policy\GuardedReservations;
use Omnifood\Policy\GuardedStore;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\PolicyGuard;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Omnisense\Contracts\Policy\Usage;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * The platforms by name, each built once from its factory and options:
 *
 *   new Registry([new UberEatsPlatformFactory($http)], [
 *       'ubereats' => ['factory' => 'ubereats', 'options' => ['client_id' => '...', 'client_secret' => '...', 'store_id' => '...']],
 *   ]);
 *
 * Each platform's usage policy (the package's policy.json, as the platform
 * declares it) is tightened by the site's "policy" (never looser) and checked
 * against its "usage" (what the site is: commercial, open_source, contract) -
 * both beside "options" or in them: policy(), policies() and guard() give
 * them. guardedOrders(), guardedMenu(), guardedStore() and
 * guardedReservations() give a platform behind its guard (omnisense/contracts'
 * PolicyGuard), each call refused before it is sent once a limit is reached
 * (UsageLimitReachedException); get(), orders(), menus()... give the
 * platforms as before, unguarded. A webhook (notify()) is never guarded.
 * Either way the waits a platform asks for (a 429's Retry-After, a RateLimit
 * field with nothing left) hold its calls, in its Api. Every platform counts
 * in the limiter given as the third argument (in memory by default): an
 * application served by many processes gives a shared one, so that a wait
 * holds in all of them.
 */
final class Registry
{
    /** @var array<string, PlatformFactoryInterface> */
    private array $factories = [];

    /** @var array<string, PlatformInterface> */
    private array $platforms = [];

    /** @var array<string, PolicyGuard> */
    private array $guards = [];

    /** @var array<string, PlatformInterface> the guarded contracts, by "contract name" */
    private array $guarded = [];

    private readonly RateLimiterInterface $limiter;

    /**
     * @param iterable<PlatformFactoryInterface>                                                                                          $factories
     * @param array<string, array{factory: string, options?: array<string, mixed>, policy?: array<string, mixed>, usage?: array<string, mixed>}> $config
     */
    public function __construct(iterable $factories, private readonly array $config, ?RateLimiterInterface $limiter = null)
    {
        foreach ($factories as $factory) {
            $this->factories[$factory->getName()] = $factory;
        }
        $this->limiter = $limiter ?? new InMemoryRateLimiter();
    }

    public function get(string $name): PlatformInterface
    {
        return $this->platforms[$name] ??= $this->factory($name)->create($this->registryOptions($name) + $this->options($name));
    }

    /**
     * A platform built afresh, its configured options with $overrides over
     * them - keys typed in a back office, a token kept in a database. Not
     * kept: get() still gives the configured one.
     *
     * @param array<string, mixed> $overrides
     */
    public function create(string $name, array $overrides = []): PlatformInterface
    {
        return $this->factory($name)->create($this->registryOptions($name) + array_replace($this->options($name), $overrides));
    }

    public function has(string $name): bool
    {
        return isset($this->config[$name]);
    }

    /** @return array<string, mixed> the options a platform is configured with ("policy" and "usage" among them when given beside) */
    public function options(string $name): array
    {
        $entry = $this->config[$name] ?? [];
        $options = $entry['options'] ?? [];
        foreach (['policy', 'usage'] as $key) {
            if (isset($entry[$key]) && !isset($options[$key])) {
                $options[$key] = $entry[$key];
            }
        }

        return $options;
    }

    /**
     * A platform's usage policy as the site runs it: the platform's own (its
     * policy.json; unknown when it declares none), tightened by the site's
     * "policy".
     *
     * @throws UsageNotPermitted when the platform's "usage" says a use its policy forbids
     */
    public function policy(string $name): UsagePolicy
    {
        return $this->guard($name)->policy();
    }

    /**
     * Every configured platform's policy, by name: what a site's "the
     * services we use" page lists.
     *
     * @return array<string, UsagePolicy>
     */
    public function policies(): array
    {
        $policies = [];
        foreach ($this->names() as $name) {
            $policies[$name] = $this->policy($name);
        }

        return $policies;
    }

    /**
     * The guard of a platform: its policy as the site runs it, the limiter's
     * counts, the wait its Api observes. Built once.
     */
    public function guard(string $name): PolicyGuard
    {
        if (isset($this->guards[$name])) {
            return $this->guards[$name];
        }
        $platform = $this->get($name);
        $declared = $platform instanceof DeclaresUsagePolicyInterface ? $platform->usagePolicy() : UsagePolicy::unknown($platform->getName());
        if (UsagePolicy::UNNAMED === $declared->provider) {
            $declared = $declared->named($platform->getName());
        }
        $options = $this->options($name);
        $effective = $declared->tighten(self::map($options['policy'] ?? null));
        $usage = self::map($options['usage'] ?? null);
        if ([] !== $usage && [] !== $violations = $effective->violations(Usage::fromArray($usage))) {
            throw UsageNotPermitted::of($effective->provider, $violations);
        }

        return $this->guards[$name] = new PolicyGuard($effective, $this->limiter, new Backoff($this->limiter, $name, UsageLimitReachedException::class), UsageLimitReachedException::class);
    }

    /** The platform's orders behind its guard: each call ("accept", "deny"...) refused before it is sent once a limit is reached. */
    public function guardedOrders(string $name): OrdersInterface
    {
        $guarded = $this->guarded['orders '.$name] ??= new GuardedOrders($this->contract($name, OrdersInterface::class, 'take orders'), $this->guard($name));

        return $guarded instanceof OrdersInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    /** The platform's menu behind its guard ("pushMenu", "setAvailability"). */
    public function guardedMenu(string $name): MenuInterface
    {
        $guarded = $this->guarded['menu '.$name] ??= new GuardedMenu($this->contract($name, MenuInterface::class, 'take a menu'), $this->guard($name));

        return $guarded instanceof MenuInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    /** The platform's store behind its guard ("status", "pause", "resume", "setHours"). */
    public function guardedStore(string $name): StoreInterface
    {
        $guarded = $this->guarded['store '.$name] ??= new GuardedStore($this->contract($name, StoreInterface::class, 'show a store'), $this->guard($name));

        return $guarded instanceof StoreInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    /** The platform's reservations behind its guard ("createReservation", "cancelReservation"...). */
    public function guardedReservations(string $name): ReservationsInterface
    {
        $guarded = $this->guarded['reservations '.$name] ??= new GuardedReservations($this->contract($name, ReservationsInterface::class, 'take reservations'), $this->guard($name));

        return $guarded instanceof ReservationsInterface ? $guarded : throw new \LogicException('A guarded contract of another kind.');
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->config);
    }

    /** @return array<string, PlatformInterface> */
    public function all(): array
    {
        $all = [];
        foreach ($this->names() as $name) {
            $all[$name] = $this->get($name);
        }

        return $all;
    }

    /** @return list<OrdersInterface> */
    public function orders(): array
    {
        return $this->having(OrdersInterface::class);
    }

    /** @return list<MenuInterface> */
    public function menus(): array
    {
        return $this->having(MenuInterface::class);
    }

    /** @return list<StoreInterface> */
    public function stores(): array
    {
        return $this->having(StoreInterface::class);
    }

    /** @return list<ReservationsInterface> */
    public function reservations(): array
    {
        return $this->having(ReservationsInterface::class);
    }

    /** @return list<NotifiableInterface> */
    public function notifiables(): array
    {
        return $this->having(NotifiableInterface::class);
    }

    /** @return list<string> the factories installed */
    public function factories(): array
    {
        return array_keys($this->factories);
    }

    /**
     * @template T of PlatformInterface
     *
     * @param class-string<T> $contract
     *
     * @return T
     */
    private function contract(string $name, string $contract, string $operation): PlatformInterface
    {
        $platform = $this->get($name);

        return $platform instanceof $contract ? $platform : throw NotSupportedException::operation($name, $operation);
    }

    /** @return array<string, mixed> what the registry gives every platform it builds: its name ("omnifood.name"), its limiter ("omnifood.limiter") */
    private function registryOptions(string $name): array
    {
        return ['omnifood.name' => $name, 'omnifood.limiter' => $this->limiter];
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        $map = [];
        foreach (\is_array($value) ? $value : [] as $key => $one) {
            $map[(string) $key] = $one;
        }

        return $map;
    }

    private function factory(string $name): PlatformFactoryInterface
    {
        $platform = $this->config[$name] ?? throw new InvalidConfigException(\sprintf('No "%s" platform; configured: %s.', $name, implode(', ', array_keys($this->config)) ?: 'none'));

        return $this->factories[$platform['factory']] ?? throw new InvalidConfigException(\sprintf('No "%s" factory for the "%s" platform; installed: %s.', $platform['factory'], $name, implode(', ', array_keys($this->factories)) ?: 'none'));
    }

    /**
     * @template T of PlatformInterface
     *
     * @param class-string<T> $interface
     *
     * @return list<T>
     */
    private function having(string $interface): array
    {
        return array_values(array_filter($this->all(), static fn (PlatformInterface $p) => $p instanceof $interface));
    }
}
