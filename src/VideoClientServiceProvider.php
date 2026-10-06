<?php

namespace Shirahcan\VideoClient;

use Illuminate\Support\ServiceProvider;

class VideoClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/video-client.php', 'video-client');

        $this->app->singleton(VideoClient::class, function () {
            $key = (string) config('video-client.trust_key', '');

            /*
             * ⚠ FAIL LOUDLY AT RESOLUTION, NOT SILENTLY AT THE FIRST CALL. An unset key
             * is a deployment mistake, far cheaper to find here than as a 401 while a
             * client is waiting to join their call. A product that has not migrated yet
             * simply never resolves this (its gate is off).
             */
            if ($key === '') {
                throw new \RuntimeException(
                    'video-client: VIDEO_SERVICE_TRUST_KEY is not set. Issue one on the service '
                    .'with `php artisan video:issue-key <product>` and put it in this app\'s .env.'
                );
            }

            return new VideoServiceClient(
                baseUrl: (string) config('video-client.base_url'),
                trustKey: $key,
                timeout: (int) config('video-client.timeout', 15),
            );
        });

        // The shared call window, from the service's numbers (cached; see CallWindows).
        $this->app->singleton(CallWindows::class, fn ($app) => new CallWindows($app->make(VideoClient::class), $app->make('cache.store')));
        $this->app->bind(CallWindow::class, fn ($app) => $app->make(CallWindows::class)->current());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/video-client.php' => config_path('video-client.php'),
        ], 'video-client-config');
    }
}
