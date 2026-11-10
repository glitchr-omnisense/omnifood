<?php

namespace Omnifood\Http;

use Omnifood\Exception\RateLimitedException;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\RateLimitHeaders;
use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A platform's HTTP calls and the waits it asks for (omnisense/contracts'
 * Backoff, a factory's Config::backoff()): before() refuses a call while a
 * wait lasts (UsageLimitReachedException: nothing sent), after() reads each
 * answer's Retry-After and RateLimit fields and turns a 429 into a
 * RateLimitedException with the wait asked - a ProviderException, the
 * family's rule. Nothing here retries: an order accepted, a menu pushed, a
 * reservation booked and refused with 429 is the caller's to make again.
 */
final class Throttle
{
    /** @throws \Omnifood\Exception\UsageLimitReachedException while the platform's wait lasts */
    public static function before(?Backoff $backoff): void
    {
        $backoff?->clear();
    }

    /**
     * @param array<string, string|list<string>> $headers the answer's
     *
     * @throws RateLimitedException on a 429
     */
    public static function after(?Backoff $backoff, string $platform, int $status, array $headers, string $message = '', ?string $code = null): void
    {
        try {
            $backoff?->observe($status, $headers);
        } catch (UsageLimitReached $e) {
            throw Redaction::withoutArguments(new RateLimitedException($platform, '' !== $message ? $message : $e->getMessage(), $code, $e->retryAfter, $e));
        }
        if (429 === $status) {
            // No Backoff given (an Api built by hand): the refusal all the same.
            throw Redaction::withoutArguments(new RateLimitedException($platform, '' !== $message ? $message : \sprintf('%s answered 429 Too Many Requests.', $platform), $code, RateLimitHeaders::wait($status, $headers)));
        }
    }
}
