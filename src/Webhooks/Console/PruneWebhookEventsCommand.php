<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Webhooks\Contracts\WebhookEventStore;

/**
 * **Bounds `hubspot_webhook_events` against `hubspot.webhooks.retention_days`** (D-04). Left
 * unpruned, this table is the one place this package writes one row per inbound webhook item
 * forever (T-05-08, threat register).
 *
 * Mirrors `Registry\Console\SyncAssociationsCommand`'s shape: `WebhookEventStore` is resolved inside
 * `handle()`, never the constructor, so an unrelated `artisan` invocation in an application that has
 * this package installed but the table unmigrated cannot fail while the console kernel registers
 * commands. `HubspotException` -- the package's own hierarchy, never a raw SQL failure -- is caught
 * and printed; every member's message already names its own fix, so printing `getMessage()` is the
 * whole of the report.
 */
final class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'hubspot:webhooks:prune';

    protected $description = 'Delete handled hubspot_webhook_events rows older than the configured retention';

    public function handle(): int
    {
        /** @var int $retentionDays */
        $retentionDays = $this->laravel->make('config')->get('hubspot.webhooks.retention_days');

        try {
            $store = $this->laravel->make(WebhookEventStore::class);

            $deleted = $store->prune(Carbon::now()->subDays($retentionDays)->toDateTimeImmutable());
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Pruned %d handled webhook event %s older than %d days.',
            $deleted,
            $deleted === 1 ? 'record' : 'records',
            $retentionDays,
        ));

        return self::SUCCESS;
    }
}
