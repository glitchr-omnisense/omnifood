<?php

namespace Omnifood\Exception;

use Omnisense\Contracts\Policy\UsageLimitReached;

/**
 * A call refused before anything was sent to the platform: a rate limit of
 * its usage policy is reached (as the site runs it), or the platform asked to
 * wait and the wait is not over (a 429's Retry-After, a RateLimit field with
 * nothing left). Nothing was accepted, pushed or booked: the same call may be
 * made again after $retryAfter seconds.
 */
final class UsageLimitReachedException extends UsageLimitReached implements OmnifoodException
{
}
