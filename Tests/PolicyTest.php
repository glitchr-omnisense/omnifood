<?php

namespace Omnifood\Tests;

use Omnifood\Channel;
use Omnifood\Config;
use Omnifood\Exception\NotSupportedException;
use Omnifood\Exception\OmnifoodException;
use Omnifood\Exception\ProviderException;
use Omnifood\Exception\RateLimitedException;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\Http\NoRetry;
use Omnifood\Http\Throttle;
use Omnifood\Model\Capabilities;
use Omnifood\Model\DenyReason;
use Omnifood\Model\Order;
use Omnifood\Model\OrderStatus;
use Omnifood\Model\OrderType;
use Omnifood\Model\Slot;
use Omnifood\OrdersInterface;
use Omnifood\PlatformFactory;
use Omnifood\PlatformInterface;
use Omnifood\Policy\GuardedOrders;
use Omnifood\Registry;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\UsageNotPermitted;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * omnifood on omnisense/contracts' usage policies: a platform's declared
 * policy, a site's tightening, the guard Registry::guardedOrders() puts in
 * front of a platform, and what a 429 does to an order's acceptance -
 * surfaced, never retried (neither by the guard nor by the HTTP client), the
 * next calls held without being sent.
 */
final class PolicyTest extends TestCase
{
    /** @var list<array{string, string}> method and URL of each request sent */
    private array $sent = [];

    /** @var list<MockResponse> the answers, in order */
    private array $answers = [];

    public function testAPlatformDeclaresItsPolicyAndTheRegistryGivesIt(): void
    {
        $registry = $this->registry();

        self::assertSame('policied', $registry->policy('kitchen')->provider);
        self::assertSame(3, $registry->policy('kitchen')->limitsFor('accept')[0]->max);
        self::assertSame(['kitchen', 'tables'], array_keys($registry->policies()));
        self::assertSame('book', $registry->policy('tables')->provider, 'a platform that declares nothing: unknown, under its name');
        self::assertFalse($registry->policy('tables')->knowsLimits());
    }

    public function testAGuardedAcceptanceOverTheLimitIsNeverSent(): void
    {
        $registry = $this->registry();
        $this->answers = array_fill(0, 3, new MockResponse('{}'));
        $guarded = $registry->guardedOrders('kitchen');
        for ($i = 1; $i <= 3; ++$i) {
            $guarded->accept('o-'.$i);
        }

        try {
            $guarded->accept('o-4');
            self::fail('a fourth acceptance within the minute');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnifoodException::class, $e, 'one catch holds the family\'s exceptions');
            self::assertGreaterThan(0, $e->retryAfter);
        }
        self::assertCount(3, $this->sent, 'the fourth was not sent');
        self::assertSame($guarded, $registry->guardedOrders('kitchen'), 'built once');
        self::assertNotSame($guarded, $registry->get('kitchen'), 'get() gives the platform unguarded');
        self::assertInstanceOf(GuardedOrders::class, $guarded);
        self::assertSame($registry->get('kitchen'), $guarded->inner());
        self::assertSame([$registry->get('kitchen')], $registry->orders(), 'the typed lists unguarded, as before');
    }

    /** Availability pushed slot by slot (TheFork: one request each): every slot counts under a limit of requests. */
    public function testAvailabilityCountsOneRequestASlot(): void
    {
        $registry = new Registry([new StubBookFactory()], ['tables' => ['factory' => 'book', 'policy' => ['rateLimits' => [['max' => 3, 'window' => 'minute', 'operations' => ['pushAvailability']]]]]]);
        $slots = [new Slot(new \DateTimeImmutable('2026-10-10 19:00'), 4), new Slot(new \DateTimeImmutable('2026-10-10 20:00'), 4), new Slot(new \DateTimeImmutable('2026-10-10 21:00'), 0)];
        try {
            $registry->guardedReservations('tables')->pushAvailability($slots);
        } catch (NotSupportedException) {
            // The stub takes none: counted all the same, as a call that failed.
        }
        self::assertSame(0, $registry->guard('tables')->remaining('pushAvailability'));

        $this->expectException(UsageLimitReachedException::class);
        $registry->guardedReservations('tables')->pushAvailability([$slots[0]]);
    }

    public function testAContractThePlatformDoesNotKeepIsNotGuarded(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->registry()->guardedMenu('kitchen');
    }

    public function testASiteTightensThePolicyButNeverLoosensIt(): void
    {
        $tight = $this->registry(['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['accept']]]]);
        $this->answers = [new MockResponse('{}')];
        $tight->guardedOrders('kitchen')->accept('o-1');
        try {
            $tight->guardedOrders('kitchen')->accept('o-2');
            self::fail('the site allows one a minute');
        } catch (UsageLimitReachedException) {
        }
        self::assertCount(1, $this->sent);

        $this->expectException(InvalidPolicyException::class);
        $this->registry(['rateLimits' => [['max' => 10, 'window' => 'minute', 'operations' => ['accept']]]])->policy('kitchen');
    }

    public function testAUseThePolicyForbidsFailsAtOnce(): void
    {
        $registry = new Registry([new PoliciedFactory($this->http())], ['kitchen' => ['factory' => 'policied', 'usage' => ['commercial' => true]]]);

        $this->expectException(UsageNotPermitted::class);
        $registry->guardedOrders('kitchen');
    }

    /**
     * A 429 to an acceptance: a ProviderException (status 429) with the wait asked, the request
     * sent once and not again, the next call held without being sent - guarded or not.
     */
    public function testA429ToAnAcceptanceIsSurfacedNeverRetriedAndHoldsTheNextCalls(): void
    {
        $registry = $this->registry([], new InMemoryRateLimiter());
        $this->answers = [new MockResponse('{"code":"rate_limited"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']])];
        $platform = $registry->get('kitchen');
        \assert($platform instanceof OrdersInterface);

        try {
            $platform->accept('o-1');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(ProviderException::class, $e);
            self::assertSame(30, $e->retryAfter);
            self::assertSame(429, $e->status);
            self::assertSame('policied', $e->platform);
        }
        self::assertCount(1, $this->sent, 'sent once, not retried');

        foreach ([$platform, $registry->guardedOrders('kitchen')] as $orders) {
            try {
                $orders->accept('o-2');
                self::fail('held while the wait lasts');
            } catch (UsageLimitReachedException $e) {
                self::assertGreaterThan(0, $e->retryAfter);
            }
        }
        self::assertCount(1, $this->sent, 'the held calls were not sent');
    }

    /** An application's http_client that retries (framework.http_client.retry_failed): a write still goes once. */
    public function testARetryingHttpClientDoesNotSendAWriteTwice(): void
    {
        $this->answers = [
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '1']]),
            new MockResponse('{}'),
        ];
        $retrying = new RetryableHttpClient($this->http(), null, 3);
        $platform = (new PoliciedFactory($retrying))->create();
        \assert($platform instanceof OrdersInterface);

        try {
            $platform->accept('o-1');
            self::fail('the 429 surfaced');
        } catch (RateLimitedException) {
        }
        self::assertCount(1, $this->sent, 'RetryableHttpClient sent it once');
        self::assertSame($this->http()::class, NoRetry::of($this->http())::class, 'a client with no retry is kept as given');
    }

    public function testThrottleWithoutABackoffStillRefusesA429(): void
    {
        Throttle::before(null);
        Throttle::after(null, 'policied', 200, []);
        try {
            Throttle::after(null, 'policied', 429, ['retry-after' => '12'], 'Too many calls', 'rate_limited');
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(12, $e->retryAfter);
            self::assertSame('rate_limited', $e->providerCode);
            self::assertSame('[policied] Too many calls', $e->getMessage());
        }
    }

    /** @param array<string, mixed> $policy */
    private function registry(array $policy = [], ?InMemoryRateLimiter $limiter = null): Registry
    {
        $kitchen = ['factory' => 'policied'];
        if ([] !== $policy) {
            $kitchen['policy'] = $policy;
        }

        return new Registry([new PoliciedFactory($this->http()), new StubBookFactory()], ['kitchen' => $kitchen, 'tables' => ['factory' => 'book']], $limiter);
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->sent[] = [$method, $url];

            return array_shift($this->answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });
    }
}

/** A delivery platform over HTTP, as a platform package builds one: its Api throttled, its policy declared. */
final class PoliciedFactory extends PlatformFactory
{
    protected function populate(Config $config): void
    {
        $config->defaults(['omnifood.factory_name' => 'policied']);
    }

    protected function build(Config $config): PlatformInterface
    {
        return new PoliciedKitchen(NoRetry::of($this->http ?? new MockHttpClient()), $config->backoff());
    }
}

final class PoliciedKitchen implements OrdersInterface, DeclaresUsagePolicyInterface
{
    public function __construct(private readonly HttpClientInterface $http, private readonly ?Backoff $backoff)
    {
    }

    public function usagePolicy(): UsagePolicy
    {
        return UsagePolicy::fromArray([
            'provider' => 'policied',
            'rateLimits' => [['max' => 3, 'window' => 'minute', 'operations' => ['accept']]],
            'rights' => ['commercial' => 'forbidden'],
        ]);
    }

    public function getName(): string
    {
        return 'policied';
    }

    public function getChannel(): Channel
    {
        return Channel::DIRECT;
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities([OrderType::DELIVERY]);
    }

    public function order(string $ref): Order
    {
        return new Order(Channel::DIRECT, $ref, '#'.$ref, OrderType::DELIVERY, OrderStatus::NEW);
    }

    public function orders(?\DateTimeImmutable $since = null): array
    {
        return [];
    }

    public function accept(string $ref, ?\DateTimeImmutable $readyAt = null): void
    {
        $this->post('/orders/'.$ref.'/accept');
    }

    public function deny(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->post('/orders/'.$ref.'/deny');
    }

    public function ready(string $ref): void
    {
        $this->post('/orders/'.$ref.'/ready');
    }

    public function cancel(string $ref, DenyReason $reason, ?string $note = null): void
    {
        $this->post('/orders/'.$ref.'/cancel');
    }

    private function post(string $path): void
    {
        Throttle::before($this->backoff);
        $response = $this->http->request('POST', 'https://policied.example'.$path);
        Throttle::after($this->backoff, 'policied', $response->getStatusCode(), $response->getHeaders(false));
    }
}
