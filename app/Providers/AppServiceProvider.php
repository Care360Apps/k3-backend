<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Debug pages leak paths, config and SQL. Only allow them on local hosts unless explicitly opted in.
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with((string) $host, '.test');
        if (config('app.debug') && ! $localHost && ! env('ALLOW_REMOTE_DEBUG', false)) {
            config(['app.debug' => false]);
        }
    }
}
