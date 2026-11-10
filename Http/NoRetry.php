<?php

namespace Omnifood\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HTTP client a platform's Api sends with, its retries off. Symfony's
 * RetryableHttpClient (framework.http_client's retry_failed, the
 * application's http_client service) retries a 429, 423, 425, 502 and 503
 * whatever the method: an order's acceptance, a menu upload or a booking
 * would be sent again, and a 429 would never reach the platform's wait.
 * withOptions(['max_retries' => 0]) reaches the RetryableHttpClient through
 * the decorators (TraceableHttpClient, ScopingHttpClient...) and turns it
 * off; a client with no retry refuses the option (an unsupported option) and
 * is kept as given. A retrying decorator of another kind is not seen: an
 * application that wraps its client in one wraps it around reads only.
 */
final class NoRetry
{
    public static function of(HttpClientInterface $http): HttpClientInterface
    {
        try {
            return $http->withOptions(['max_retries' => 0]);
        } catch (\InvalidArgumentException) {
            return $http;
        }
    }
}
