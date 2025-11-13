<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useTailwind();

        \Illuminate\Support\Facades\URL::forceScheme('https');
    }
}
