<?php

namespace Omnifood\Http;

use Omnisense\Contracts\Http\NoRetry as KernelNoRetry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client a platform's Api sends with, its retries off. Symfony's
 * RetryableHttpClient (framework.http_client's retry_failed, the
 * application's http_client service) retries a 429, 423, 425, 502 and 503
 * whatever the method: an order's acceptance, a menu upload or a booking
 * would be sent again, and a 429 would never reach the platform's wait.
 *
 * Since omnisense/contracts 1568f2c this is the kernel's
 * Omnisense\Contracts\Http\NoRetry::of(), kept here under its public name.
 * With an older kernel (a site's vendor), the former code below runs:
 * withOptions(['max_retries' => 0]) reaches the RetryableHttpClient through
 * the decorators (TraceableHttpClient, ScopingHttpClient...) and turns it
 * off; a client with no retry refuses the option (an unsupported option) and
 * is kept as given, and so is a MockHttpClient, which retries nothing (its
 * copy would count requests getRequestsCount() no longer sees). A retrying
 * decorator of another kind is not seen: an application that wraps its
 * client in one wraps it around reads only.
 */
final class NoRetry
{
    public static function of(HttpClientInterface $http): HttpClientInterface
    {
        if (class_exists(KernelNoRetry::class)) {
            return KernelNoRetry::of($http);
        }
        if (is_a($http, 'Symfony\\Component\\HttpClient\\MockHttpClient')) {
            return $http;
        }
        try {
            return $http->withOptions(['max_retries' => 0]);
        } catch (\InvalidArgumentException) {
            return $http;
        }
    }
}
