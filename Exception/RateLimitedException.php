<?php

namespace Omnifood\Exception;

/**
 * The platform answered 429 Too Many Requests: it refused the call, a
 * ProviderException as every refusal (status 429), with the seconds to wait
 * when the platform said ($retryAfter, from Retry-After or RateLimit). The
 * platform's next calls wait as long (UsageLimitReachedException before they
 * are sent). Nothing retries it: an order accepted, a menu pushed, a
 * reservation created is never sent twice by Omnifood.
 */
final class RateLimitedException extends ProviderException
{
    public function __construct(
        string $platform,
        string $message,
        ?string $providerCode = null,
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($platform, $message, 429, $providerCode, $previous);
    }
}
