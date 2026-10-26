---
title: Testing
order: 60
---

# Testing

Testing an application that uses Omnifood. The core ships no fake platform and no conformance
suite: an application tests against a platform package on recorded answers, or against a
platform of its own.

## A platform on recorded answers

Give the platform's factory a `MockHttpClient` (symfony/http-client) that answers as the platform
would. The token endpoint is called first, on the first call that needs a token:

```php
use Omnifood\Exception\ProviderException;
use Omnifood\Model\OrderStatus;
use Omnifood\UberEats\UberEatsPlatformFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KitchenTest extends TestCase
{
    private function ubereats(MockResponse ...$answers)
    {
        $http = new MockHttpClient(static function (string $method, string $url) use (&$answers): MockResponse {
            return str_contains($url, '/oauth/v2/token')
                ? new MockResponse('{"access_token": "test", "expires_in": 2592000}')
                : array_shift($answers);
        });

        return (new UberEatsPlatformFactory($http))->create(['client_id' => 'id', 'client_secret' => 'secret', 'store_id' => 'store-1']);
    }

    public function testANewOrderIsShown(): void
    {
        $ubereats = $this->ubereats(new MockResponse((string) file_get_contents(__DIR__.'/fixtures/ubereats-order.json')));

        self::assertSame(OrderStatus::NEW, $ubereats->order('o-1')->status);
    }

    public function testUberDownIsAProviderException(): void
    {
        $this->expectException(ProviderException::class);
        $this->ubereats(new MockResponse('', ['http_code' => 503]))->accept('o-1');
    }
}
```

A recorded order to start from: `docker/harness/recorded/ubereats-order.json` in this package;
each platform package's `Tests/` holds the answers its own tests record, written from the
platform's documentation.

## Webhooks

`notify()` needs nothing but the secret: sign the body in the test as the platform does.

```php
$body = '{"event_id": "e-1", "event_type": "orders.notification", "meta": {"resource_id": "o-1"}}';
$notification = $ubereats->notify($body, ['X-Uber-Signature' => hash_hmac('sha256', $body, 'secret')]);
```

Deliveroo signs `<guid> <body>` with the webhook secret
(`X-Deliveroo-Sequence-Guid`, `X-Deliveroo-Hmac-Sha256`); Just Eat sends the configured key as
`Authorization` and `HMAC-SHA256 t=...,signature=<base64>` as `X-JET-Connect-Hash`; TheFork's
token goes among the headers as `token`.

## A platform of the application's own

To test the application's code without any platform package, a class implementing the interfaces
it uses, and a factory for it ([Writing a platform](providers.md)), configured under the name the
application uses. The core's own tests do so (`Tests/StubFactory.php`: a platform that records
what it was asked).

## Menus

`Validator` needs no platform: check a menu against `new Capabilities(...)` with the limits to
test, or against a platform built with no key (`(new DeliverooPlatformFactory())->create()->capabilities()`).

## In a Symfony functional test

The registry and the platforms are services: replace a platform's options per environment
(`config/packages/test/omnifood.yaml`, a factory of the application's own), or replace the HTTP
client the factories receive (`http_client`) with a `MockHttpClient` in the test environment, as
for any Symfony HTTP client test.
