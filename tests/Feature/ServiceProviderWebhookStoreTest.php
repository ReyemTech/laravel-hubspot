<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use ReyemTech\Hubspot\ServiceProvider;
use ReyemTech\Hubspot\Tests\TestCase;
use ReyemTech\Hubspot\Webhooks\Stores\DatabaseWebhookEventStore;

/**
 * Isolated from `WebhookEventStoreTest` deliberately, mirroring `ServiceProviderDatabaseStoreTest`'s
 * own documented reason: `hubspot.webhooks.enabled` decides, in `ServiceProvider::boot()`, whether
 * `database/migrations/webhooks` is registered with the migrator at all -- every test in
 * `WebhookEventStoreTest` needs it forced TRUE via `defineEnvironment()`, and a single shared
 * application cannot un-apply that retroactively per test. HOOK-03's shipped, off-by-default state
 * therefore needs its own class, over the unmodified default environment.
 */
mutates(ServiceProvider::class);

final class ServiceProviderWebhookStoreTest extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    public function test_a_default_install_registers_no_webhook_migration_path_and_migrate_creates_no_table(): void
    {
        self::assertFalse(
            config('hubspot.webhooks.enabled'),
            'HOOK-03 requires webhooks off by default -- this test is only meaningful against the '
            .'shipped default.',
        );

        Artisan::call('migrate', ['--force' => true]);

        self::assertFalse(Schema::hasTable(DatabaseWebhookEventStore::TABLE));
    }
}
