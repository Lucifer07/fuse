<?php

namespace Tests;

use Fuse\FuseServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class FuseTestCase extends BaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            FuseServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('fuse.enabled', true);
        $app['config']->set('fuse.default_threshold', 50);
        $app['config']->set('fuse.default_timeout', 60);
        $app['config']->set('fuse.default_min_requests', 10);
    }
}
