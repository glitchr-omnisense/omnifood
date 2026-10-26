---
title: Laravel
order: 16
---

# Laravel

omnibridge/laravel, the bridge that hosts omnibase in Laravel, has no Omnifood provider: none is
needed. The registry is plain PHP; a service provider of the application builds it once, from a
config file, with a `symfony/http-client` client (every platform package requires that library;
Laravel's own HTTP client is Guzzle's, which the platforms do not take).

```php
// config/omnifood.php
return [
    'platforms' => [
        'ubereats' => ['factory' => 'ubereats', 'options' => [
            'client_id' => env('UBEREATS_CLIENT_ID'),
            'client_secret' => env('UBEREATS_CLIENT_SECRET'),
            'store_id' => env('UBEREATS_STORE_ID'),
        ]],
        'thefork' => ['factory' => 'thefork', 'options' => [
            'client_id' => env('THEFORK_CLIENT_ID'),
            'client_secret' => env('THEFORK_CLIENT_SECRET'),
            'restaurant_id' => env('THEFORK_RESTAURANT_ID'),
            'webhook_token' => env('THEFORK_WEBHOOK_TOKEN'),
        ]],
    ],
];
```

```php
// app/Providers/OmnifoodServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Omnifood\Registry;
use Omnifood\TheFork\TheForkPlatformFactory;
use Omnifood\UberEats\UberEatsPlatformFactory;
use Omnifood\Validator;
use Symfony\Component\HttpClient\HttpClient;

final class OmnifoodServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Registry::class, function (): Registry {
            $http = HttpClient::create();

            return new Registry([
                new UberEatsPlatformFactory($http),
                new TheForkPlatformFactory($http),
            ], config('omnifood.platforms'));
        });
        $this->app->singleton(Validator::class);
    }
}
```

Register the provider (`bootstrap/providers.php` on Laravel 11 and later), then inject the
registry where it is needed:

```php
use Illuminate\Http\Request;
use Omnifood\Exception\InvalidSignatureException;
use Omnifood\Registry;

final class UberEatsWebhookController
{
    public function __invoke(Request $request, Registry $registry)
    {
        try {
            $notification = $registry->get('ubereats')->notify($request->getContent(), $request->headers->all());
        } catch (InvalidSignatureException) {
            return response('', 401);
        }
        HandleNotification::dispatch($notification->channel->value, $notification->id, $notification->reference);   // the application's job

        return response('', 200);
    }
}
```

`env()` gives `null` for a variable not set, which a factory reads as "not given". List in the
provider every platform factory the application uses: unlike the Symfony bundle, a hand-built
registry knows only the factories it is given. Configuration caching (`php artisan config:cache`)
keeps working: the options are plain arrays read through `config()`. Exclude the webhook routes
from CSRF verification, as for any webhook. This page was written from the registry's API; it was
not run in a Laravel application.
