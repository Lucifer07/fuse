<?php

namespace Fuse;

use Illuminate\Support\ServiceProvider;

class FuseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/fuse.php' => config_path('fuse.php'),
        ], 'fuse-config');
    }
}
