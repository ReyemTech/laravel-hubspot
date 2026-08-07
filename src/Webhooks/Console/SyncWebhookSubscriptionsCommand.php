<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Webhooks\Console;

use Illuminate\Console\Command;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\Contracts\WebhookSubscriptionGatewayContract;
use ReyemTech\Hubspot\Gateway\WebhookSubscription;
use ReyemTech\Hubspot\Webhooks\AppModel;
use ReyemTech\Hubspot\Webhooks\ManualSetupInstructions;
use ReyemTech\Hubspot\Webhooks\ProjectWebhookComponent;
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
 * ## Three app models, three different behaviours (D-16)
 *
 * `legacy_public` reconciles subscriptions through `WebhookSubscriptionGatewayContract` -- the
 * only path that ever calls HubSpot. `legacy_private` renders validated manual setup instructions
 * via `Webhooks\ManualSetupInstructions`, and `project` renders an exportable webhook component via
 * `Webhooks\ProjectWebhookComponent` -- neither of the latter two ever resolves the gateway,
 * because neither app model exposes a runtime subscription-management API HubSpot lets this
 * package call. Dispatched through an explicit `match` over `Webhooks\AppModel` so a future app
 * model adds a branch rather than rewriting this method.
 */
final class SyncWebhookSubscriptionsCommand extends Command
{
    protected $signature = 'hubspot:webhooks:sync
        {--dry-run : Print the diff without writing anything}
        {--output= : Write the rendered project webhook component to this path instead of stdout}';

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
            AppModel::LegacyPrivate => $this->legacyPrivate($declarations),
            AppModel::Project => $this->project($declarations),
        };
    }

    /**
     * The `legacy_private` branch: validated, rendered manual setup guidance, never a HubSpot
     * request. See {@see ManualSetupInstructions} for why -- HubSpot exposes no
     * subscription-management API for this app model at all.
     */
    private function legacyPrivate(SubscriptionDeclarations $declarations): int
    {
        try {
            $declared = $declarations->all();
            $targetUrl = $this->targetUrl();
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach (ManualSetupInstructions::render($declared, $targetUrl) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * `hubspot.webhooks.target_url`, validated. Both non-API artefacts embed this value, and a
     * wrong one silently sends production traffic elsewhere -- see
     * `ConfigurationException::missingWebhookTargetUrl()`.
     */
    private function targetUrl(): string
    {
        /** @var mixed $raw */
        $raw = $this->laravel->make('config')->get('hubspot.webhooks.target_url');

        if (! is_string($raw) || trim($raw) === '') {
            throw ConfigurationException::missingWebhookTargetUrl();
        }

        return $raw;
    }

    /**
     * The `project` branch: an exportable webhook component, never a HubSpot request. See
     * {@see ProjectWebhookComponent} for why -- a project-based app declares its subscriptions in
     * a config artefact deployed WITH the project rather than through a runtime API.
     */
    private function project(SubscriptionDeclarations $declarations): int
    {
        try {
            $declared = $declarations->all();
            $targetUrl = $this->targetUrl();
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $json = ProjectWebhookComponent::encode(ProjectWebhookComponent::render($declared, $targetUrl));

        /** @var mixed $rawOutput */
        $rawOutput = $this->option('output');
        $outputPath = is_string($rawOutput) && $rawOutput !== '' ? $rawOutput : null;

        if ($outputPath === null) {
            $this->line($json);
            $this->line('');
            $this->line(
                'No --output given, so nothing was written to disk. Place this file at '
                .'src/app/webhooks/<name>-hsmeta.json inside your HubSpot project so it deploys '
                .'with your app.',
            );
        } elseif ((bool) $this->option('dry-run')) {
            // The (bool) cast here is an equivalent mutant under pest --mutate's
            // RemoveBooleanCast, matching the identical --dry-run cast in syncLegacyPublic()'s
            // own match arm above: `--dry-run` is declared with no value (a flag), so Symfony
            // Console's InputOption::VALUE_NONE already returns a strict bool from option() --
            // there is no other type on the wire the cast could be narrowing.
            $this->line(sprintf('[dry run] Nothing written -- %s was not created.', $outputPath));
        } else {
            file_put_contents($outputPath, $json);
            $this->line(sprintf('Wrote %s.', $outputPath));
        }

        // The blank line() calls above and below are visual spacing, not content -- see
        // tests/Support/CommandOutput.php's own docblock for why this package deliberately does
        // not pin blank-line presence/count in rendered console output.
        $this->line('');
        $this->line('Nothing was changed in HubSpot. This component ships with your project deployment.');

        return self::SUCCESS;
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
