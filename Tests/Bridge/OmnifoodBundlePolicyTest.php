<?php

namespace Omnifood\Tests\Bridge;

use Omnifood\Bridge\Symfony\OmnifoodBundle;
use Omnifood\Exception\UsageLimitReachedException;
use Omnifood\Registry;
use Omnifood\Tests\StubFactory;
use Omnisense\Contracts\Policy\InMemoryRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;

/** The bundle's "policy", "usage" and "limiter": a site's tightening, counted in the limiter service it names. */
final class OmnifoodBundlePolicyTest extends TestCase
{
    public function testAPlatformsPolicyAndTheSharedLimiterComeFromConfiguration(): void
    {
        $kernel = new OmnifoodPolicyKernel();
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            $registry = $container->get(Registry::class);
            self::assertInstanceOf(Registry::class, $registry);
            self::assertSame(1, $registry->policy('kitchen')->limitsFor('accept')[0]->max);

            $orders = $registry->guardedOrders('kitchen');
            $orders->accept('o-1');
            try {
                $orders->accept('o-2');
                self::fail('one a minute');
            } catch (UsageLimitReachedException) {
            }
            $limiter = $container->get('app.limiter');
            self::assertInstanceOf(InMemoryRateLimiter::class, $limiter);
            $fresh = new Registry([new StubFactory()], ['kitchen' => ['factory' => 'stub', 'options' => ['key' => 'k'], 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['accept']]]]]], $limiter);
            $this->expectException(UsageLimitReachedException::class);
            $fresh->guardedOrders('kitchen')->accept('o-3');
        } finally {
            $dir = $kernel->getProjectDir();
            $kernel->shutdown();
            restore_exception_handler();
            (new \Symfony\Component\Filesystem\Filesystem())->remove($dir);
        }
    }
}

final class OmnifoodPolicyKernel extends Kernel
{
    private readonly string $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/omnifood-policy-'.bin2hex(random_bytes(6));
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new OmnifoodBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('omnifood', [
                'platforms' => ['kitchen' => [
                    'factory' => 'stub',
                    'options' => ['key' => 'k'],
                    'policy' => ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['accept']]]],
                    'usage' => ['commercial' => true],
                ]],
                'limiter' => 'app.limiter',
            ]);
            $container->register('http_client', MockHttpClient::class);
            $container->register('app.limiter', InMemoryRateLimiter::class)->setPublic(true);
            $container->register(StubFactory::class)->setAutoconfigured(true);
        });
    }

    public function getProjectDir(): string
    {
        return $this->dir;
    }
}
