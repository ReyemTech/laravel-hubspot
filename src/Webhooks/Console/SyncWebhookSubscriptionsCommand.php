<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Console;

use Illuminate\Console\Command;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;
use ReyemTech\Hubspot\Webhooks\AppModel;
use ReyemTech\Hubspot\Webhooks\SubscriptionDeclarations;

/**
 * **`hubspot:webhooks:sync` -- HOOK-02's runtime half, legacy-public reconciliation only.**
 *
 * Creates a declaration the portal lacks, updates one whose `active` state differs, reports every
 * unmanaged extra by name, and deletes nothing (D-11) -- there is no code path that could, since
 * {@see WebhookSubscriptionGatewayContract} declares no removal method. `--dry-run` runs the
 * identical read and diff and suppresses only the write calls.
 *
 * Modelled directly on `Registry\Console\SyncAssociationsCommand`: the tally resets inside
 * `handle()` rather than as a property default, because Artisan reuses one command instance across
 * calls in a process; `WebhookSubscriptionGatewayContract` is resolved with
 * `$this->laravel->make(...)` inside `handle()`, never the constructor, so an application with no
 * management credentials configured can still run an unrelated `artisan` command; and a caught
 * `HubspotException` is printed via `getMessage()` alone, since every member's message already
 * names its own fix.
 *
 * ## Only `legacy_public` is implemented
 *
 * `hubspot.webhooks.app_model` accepts `legacy_private` and `project` too (D-16), and both are
 * a later plan's work. Dispatched through an explicit `match` over `Webhooks\AppModel` so that
 * plan adds branches rather than rewriting this method -- and both unfinished branches fail with a
 * directed message rather than exiting zero having done nothing, which is this package's own
 * standing rule against a silent no-op.
 */
final class SyncWebhookSubscriptionsCommand extends Command
{
    protected $signature = 'hubspot:webhooks:sync {--dry-run : Print the diff without writing anything}';

    protected $description = 'Reconcile configured webhook subscriptions into HubSpot without ever deleting one';

    /**
     * @var array<string, int>
     */
    private array $tally = [];

    public function handle(SubscriptionDeclarations $declarations): int
    {
        // Reset per run, not initialised as a property default -- see the class docblock.
        $this->tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        /** @var mixed $rawAppModel */
        $rawAppModel = $this->laravel->make('config')->get('hubspot.webhooks.app_model');

        try {
            $appModel = AppModel::resolve($rawAppModel);
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return match ($appModel) {
            AppModel::LegacyPublic => $this->syncLegacyPublic($declarations, (bool) $this->option('dry-run')),
            AppModel::LegacyPrivate, AppModel::Project => $this->notYetImplemented($appModel),
        };
    }

    private function notYetImplemented(AppModel $appModel): int
    {
        $this->error(sprintf(
            'hubspot.webhooks.app_model is "%s", which this release does not reconcile yet -- only '
            .'"legacy_public" runs hubspot:webhooks:sync today. Webhook RECEIPT already works for '
            .'every app model regardless of this command.',
            $appModel->value,
        ));

        return self::FAILURE;
    }

    private function syncLegacyPublic(SubscriptionDeclarations $declarations, bool $dryRun): int
    {
        try {
            $declared = $declarations->all();
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($declared === []) {
            $this->error(
                'hubspot.webhooks.subscriptions is empty. Add at least one declaration, for '
                .'example ["event_type" => "contact.propertyChange", "property_name" => "email"], '
                .'then run this command again.',
            );

            return self::FAILURE;
        }

        try {
            $gateway = $this->laravel->make(WebhookSubscriptionGatewayContract::class);

            $portal = $gateway->list();

            /** @var array<string, WebhookSubscription> $portalByIdentity */
            $portalByIdentity = [];

            foreach ($portal as $subscription) {
                $portalByIdentity[$subscription->identity()] = $subscription;
            }

            /** @var array<string, true> $matchedIdentities */
            $matchedIdentities = [];

            foreach ($declared as $subscription) {
                $matchedIdentities[$subscription->identity()] = true;

                $this->reconcile($gateway, $subscription, $portalByIdentity[$subscription->identity()] ?? null, $dryRun);
            }
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->reportExtras($portal, $matchedIdentities);

        $this->line(sprintf(
            '%s%d created, %d updated, %d unchanged.',
            $dryRun ? '[dry run] ' : '',
            $this->tally['created'],
            $this->tally['updated'],
            $this->tally['unchanged'],
        ));

        return self::SUCCESS;
    }

    /**
     * One declaration: create it, update it, or count it unchanged. Matching is by
     * {@see WebhookSubscription::identity()}, never by array position and never by the portal id --
     * a declaration with no matching portal row has no portal id yet.
     */
    private function reconcile(
        WebhookSubscriptionGatewayContract $gateway,
        WebhookSubscription $declared,
        ?WebhookSubscription $existing,
        bool $dryRun,
    ): void {
        if ($existing === null) {
            $this->tally['created']++;
            $this->line(sprintf('created %s', self::describe($declared)));

            if (! $dryRun) {
                $gateway->create($declared);
            }

            return;
        }

        if ($existing->active === $declared->active) {
            $this->tally['unchanged']++;
            $this->line(sprintf('unchanged %s', self::describe($declared)));

            return;
        }

        $this->tally['updated']++;
        $this->line(sprintf('updated %s', self::describe($declared)));

        if (! $dryRun) {
            $gateway->update(new WebhookSubscription(
                eventType: $declared->eventType,
                propertyName: $declared->propertyName,
                active: $declared->active,
                portalId: $existing->portalId,
            ));
        }
    }

    /**
     * Portal subscriptions matching no declaration -- reported sorted (must-haves: two runs
     * against one portal produce identical text), named, and never the subject of a write.
     *
     * @param  list<WebhookSubscription>  $portal
     * @param  array<string, true>  $matchedIdentities
     */
    private function reportExtras(array $portal, array $matchedIdentities): void
    {
        $extras = [];

        foreach ($portal as $subscription) {
            if (! isset($matchedIdentities[$subscription->identity()])) {
                $extras[] = self::describe($subscription);
            }
        }

        if ($extras === []) {
            return;
        }

        sort($extras);

        $this->line(sprintf(
            'Not managed by this package (nothing removed): %s',
            implode(', ', $extras),
        ));
    }

    private static function describe(WebhookSubscription $subscription): string
    {
        return $subscription->propertyName === null
            ? sprintf('"%s"', $subscription->eventType)
            : sprintf('"%s" (%s)', $subscription->eventType, $subscription->propertyName);
    }
}
