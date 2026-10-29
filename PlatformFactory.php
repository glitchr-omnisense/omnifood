<?php

namespace Omnifood;

use Omnifood\Http\Redaction;
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
 */
abstract class PlatformFactory implements PlatformFactoryInterface
{
    public function __construct(protected readonly ?HttpClientInterface $http = null)
    {
    }

    public function getName(): string
    {
        return $this->createConfig()['omnifood.factory_name'];
    }

    public function create(array $options = []): PlatformInterface
    {
        try {
            $config = $this->createConfig($options);
            $config->validateNotEmpty($config->get('omnifood.required_options', []));

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
