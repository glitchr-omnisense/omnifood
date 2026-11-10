<?php

namespace Omnifood;

use Omnifood\Exception\InvalidConfigException;

/**
 * A platform's configuration while its factory builds it: the options given,
 * the factory's defaults under them, and the keys that must be filled -
 * omnisense/contracts' Config, with omnifood's prefix ("omnifood.factory_name",
 * "omnifood.backoff"...) and its InvalidConfigException. Two options belong
 * to every platform besides its own: "policy" (the site's tightening of its
 * usage policy) and "usage" (what the site says of itself: commercial,
 * open_source, contract).
 */
final class Config extends \Omnisense\Contracts\Provider\Config
{
    /** @var string */
    public const PREFIX = 'omnifood';

    /** @param list<string> $keys */
    public function validateNotEmpty(array $keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key) => !isset($this[$key]) || '' === $this[$key] || [] === $this[$key]));
        if ($missing) {
            throw new InvalidConfigException(\sprintf('The "%s" platform needs: %s.', $this->string('omnifood.factory_name') ?? '?', implode(', ', $missing)));
        }
    }

    protected function invalid(string $message): \Throwable
    {
        return new InvalidConfigException($message);
    }
}
