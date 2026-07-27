<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ReyemTech\Hubspot\ServiceProvider;

class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }
}
