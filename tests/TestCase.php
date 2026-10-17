<?php

namespace Preuvia\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Preuvia\Laravel\PreuviaServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PreuviaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('preuvia.base_url', 'https://preuvia.test/api/v1');
        $app['config']->set('preuvia.api_key', 'preuvia_test_key');
        $app['config']->set('preuvia.webhooks.secret', 'whsec_test');
    }
}
