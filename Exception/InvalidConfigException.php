<?php

namespace Omnifood\Exception;

/**
 * A platform misconfigured: a credential missing, an unknown factory. It is
 * omnisense/contracts' InvalidConfigException (an \InvalidArgumentException,
 * so a \LogicException as before).
 */
final class InvalidConfigException extends \Omnisense\Contracts\Exception\InvalidConfigException implements OmnifoodException
{
}
