<?php

namespace Omnifood;

use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\Http\NoRetry;
use Omnifood\Http\Redaction;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\RateLimiterInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Omnibus way: a platform's factory fills a Config - its name
 * ("omnifood.factory_name"), the options it cannot do without
 * ("omnifood.required_options") and the defaults of the others - and builds
 * its client from it.
 *
 * Options left empty (null, '': an environment variable not set) are as if
 * not given: the defaults stand. A credential is best not required here but
 * asked for when a call needs it, so that a platform without its keys still
 * answers what needs none (capabilities(), a webhook's signature check).
 *
 * Before build(), the Config holds the platform's Backoff
 * ("omnifood.backoff", Config::backoff()), over the registry's limiter
 * ("omnifood.limiter") or an in-memory one, as omnisense/contracts'
 * ProviderFactory gives it: the platform's Api observes every answer with
 * it (Http\Throttle), so that the waits the platform asks for hold its calls.
 *
 * The HTTP client given is kept with its retries off (Http\NoRetry, the
 * kernel's since omnisense/contracts 1568f2c): an application's own
 * platform, whose Api does not call NoRetry itself, never sends an order's
 * acceptance twice through Symfony's RetryableHttpClient either.
 */
abstract class PlatformFactory implements PlatformFactoryInterface
{
    protected readonly ?HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = null !== $http ? NoRetry::of($http) : null;
    }

    public function getName(): string
    {
        return $this->createConfig()['omnifood.factory_name'];
    }

    public function create(array $options = []): PlatformInterface
    {
        try {
            $config = $this->createConfig($options);
            $required = $config->get('omnifood.required_options', []);
            $config->validateNotEmpty(\is_array($required) ? array_values(array_filter($required, 'is_string')) : []);
            $limiter = $config->get('omnifood.limiter');
            $config['omnifood.backoff'] = new Backoff(
                $limiter instanceof RateLimiterInterface ? $limiter : new InMemoryRateLimiter(),
                // The name it is configured under (the registry gives it): its wait is that platform's.
                $config->string('omnifood.name') ?? $config->string('omnifood.factory_name') ?? static::class,
                UsageLimitReachedException::class,
            );

            return $this->build($config);
        } catch (\Throwable $e) {
            // The options hold the credentials: no trace of the failure keeps them in its arguments.
            throw Redaction::withoutArguments($e);
        }
    }

    public function createConfig(array $options = []): Config
    {
        $config = new Config(array_filter($options, static fn ($value) => null !== $value && '' !== $value));
        $this->populate($config);

        return $config;
    }

    /** The factory's name, required options and defaults. */
    abstract protected function populate(Config $config): void;

    abstract protected function build(Config $config): PlatformInterface;
}
