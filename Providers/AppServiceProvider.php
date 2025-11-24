<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useTailwind();

        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
            
            $appUrl = config('app.url');
            if (str_starts_with($appUrl, 'http://')) {
                $appUrl = str_replace('http://', 'https://', $appUrl);
            }
            \Illuminate\Support\Facades\URL::forceRootUrl($appUrl);
        }
    }
}
