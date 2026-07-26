<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [];
    }
}
