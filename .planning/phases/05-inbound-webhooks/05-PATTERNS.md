# Phase 5: Inbound Webhooks - Pattern Map

**Mapped:** 2026-08-06  
**Files analysed:** 28 planned production/config/migration files; 9 test files  
**Analogs found:** 25 / 28 production files (the three project-app artifacts are intentionally new)

## File Classification

| New/modified file(s) | Role | Data flow | Closest analog | Match |
|---|---|---|---|---|
| `src/Gateway/Contracts/WebhookGatewayContract.php`, `src/Gateway/WebhookGateway.php` | contract/service | request-response | `Gateway/Contracts/AssociationDefinitionsGatewayContract.php`, `Gateway/AssociationDefinitionsGateway.php` | exact role |
| `src/Gateway/Contracts/WebhookSubscriptionGatewayContract.php`, subscription values, `src/Gateway/WebhookSubscriptionGateway.php` | contract/service/model | CRUD | association-definitions contract/gateway | role-match |
| `src/Webhooks/RouteRegistrar.php`, `src/Webhooks/WebhookController.php` | route/controller | request-response, batch | `ServiceProvider.php`, `Sync/HubspotObserver.php` | role/data-flow match |
| `src/Webhooks/NormalizedWebhookEvent.php`, `Events/*` | model/event | transform, event-driven | `Gateway/AssociationDefinition.php`, `Gateway/HubspotObject.php` | role-match |
| `src/Webhooks/ProcessWebhookEventJob.php` | job | queued, event-driven | `Sync/SyncHubspotObjectJob.php` | exact role |
| `src/Webhooks/Contracts/WebhookEventStore.php`, `DatabaseWebhookEventStore.php`, event record/model | contract/service/model | CRUD, event-driven | `Registry/Contracts/AssociationTypeStore.php`, `Registry/Stores/DatabaseAssociationTypeStore.php` | role/data-flow match |
| `database/migrations/webhooks/*create_hubspot_webhook_events_table.php` | migration | file-I/O / CRUD | `database/migrations/sync/0001_01_01_000000_create_hubspot_object_links_table.php` | exact role |
| `src/Webhooks/Contracts/WebhookHandler.php`, handler resolver/dispatcher | contract/service | event-driven | `Gateway` contract + container resolution in `Registry/Console/SyncAssociationsCommand.php` | role-match |
| `src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php`, `PruneWebhookEventsCommand.php` | console command | CRUD, batch | `Registry/Console/SyncAssociationsCommand.php` | exact role |
| `src/Webhooks/ProjectWebhookComponentExporter.php` (or rendered component value) | service/utility | transform, file-I/O | none | no close analog |
| `config/hubspot.php`, `src/ServiceProvider.php` | config/provider | request-response / config | themselves | exact modification |
| `src/HubspotManager.php`, `src/Facades/Hubspot.php`, `src/Testing/HubspotFake.php` | manager/facade/test utility | event-driven | themselves | exact modification |
| `tests/Feature/Webhooks/*`, `tests/Unit/Webhooks/*` | test | request-response, queued, CRUD | `tests/Feature/Sync/BatchSyncTest.php`, Registry store/command tests | role/data-flow match |

## Pattern Assignments

### Gateway webhook verification and subscription port

**Create:** `src/Gateway/Contracts/WebhookGatewayContract.php`, `src/Gateway/WebhookGateway.php`, and a separate subscription-management contract/adapter with package-owned request/value objects.

**Analog:** `src/Gateway/Contracts/AssociationDefinitionsGatewayContract.php` lines 5-59 and `src/Gateway/AssociationDefinitionsGateway.php` lines 5-105.

**Copy the contract boundary:** contracts expose package values and package exceptions; only the Gateway implementation imports SDK types.

```php
interface AssociationDefinitionsGatewayContract
{
    /** @return list<AssociationDefinition> */
    public function listFor(string $fromObjectType, string $toObjectType): array;
}
```

```php
final class AssociationDefinitionsGateway implements AssociationDefinitionsGatewayContract
{
    public function __construct(
        private readonly HubspotClientFactory $clientFactory,
        private readonly ExceptionTranslator $exceptionTranslator,
    ) {}

    public function listFor(string $fromObjectType, string $toObjectType): array
    {
        try {
            $result = $this->definitionsApi()->getPage($fromObjectType, $toObjectType);
        } catch (SdkAssociationsV4SchemaApiException $exception) {
            throw $this->exceptionTranslator->translate($exception);
        }
        // Validate SDK response shape before converting it to package values.
    }
}
```

**Phase-specific application:** the signature adapter is the *only* Phase 5 file that may call `HubSpot\Utils\Signature::isValid()`. It receives the raw URI/body/timestamp and returns a package-owned boolean/result. Webhooks code must not name any `HubSpot\*` class or catch raw SDK exceptions.

**Subscription policy (locked D-16):** keep three explicit Gateway-capability paths, never a credential fallback:

| App model | Product artifact | Runtime behavior |
|---|---|---|
| Legacy public | Gateway subscription adapter | `hubspot:webhooks:sync` reconciles config using app ID + Developer API key; create/update only and report extras. |
| Legacy private | directed validation/manual-instructions service | No runtime subscription API call; validate/render manual setup guidance. |
| Current project-based | `ProjectWebhookComponentExporter` | Export a webhook component for project deployment; no runtime mutation. |

Do not add a Webhook Journal contract, SDK call, command, or configuration. It is a distinct pull API and out of scope. HubSpot Service Keys are not accepted by any subscription-management path.

### HTTP receipt route

**Create:** `src/Webhooks/RouteRegistrar.php` and `src/Webhooks/WebhookController.php` (or equivalent invokable route adapter).

**Analogs:** `src/ServiceProvider.php` lines 183-217 (boot-time registration) and `src/Sync/HubspotObserver.php` lines 731-749 (injected Dispatcher dispatch).

**Copy provider registration shape:**

```php
public function boot(): void
{
    if ($this->app->runningInConsole()) {
        $this->commands(self::consoleCommands());
    }

    $this->publishes([...], 'hubspot-config');
}
```

Register the `Route::hubspotWebhook()` macro from the package provider/facade seam; do not require a routes file. The controller must preserve the original request URI/query ordering and raw content, invoke the Gateway verifier *before* JSON decode, then dispatch one job per validated item. Map invalid/missing signature to 401, signed malformed input to 400 with no payload log, dispatch failure to 500, and a completely handed-off batch to 204.

### Normalized event classes and configured handlers

**Create:** `src/Webhooks/NormalizedWebhookEvent.php`, generic receipt event plus property-change, lifecycle, and association typed events, `Contracts/WebhookHandler.php`, and a handler resolver/dispatcher.

**Analog:** package value-object shape in `src/Gateway/AssociationDefinitionsGateway.php` lines 81-100 and package contract shape in `src/Gateway/Contracts/AssociationDefinitionsGatewayContract.php` lines 40-59.

```php
private function toDefinitions(array $results): array
{
    $definitions = [];

    foreach ($results as $spec) {
        $definitions[] = new AssociationDefinition(
            type: new AssociationType(...),
            label: $spec->getLabel(),
        );
    }

    return $definitions;
}
```

Make the normalized object immutable and package-owned; all listeners and configured handlers receive that same value. The job must dispatch generic first, typed second when recognized, then invoke the resolved configured event-key handlers and `*` handlers. Validate handler class strings and interface conformance before delivery processing; use `$this->laravel->make(...)` at execution time as the console command does at `SyncAssociationsCommand.php` lines 99-126, so invalid configuration gets a directed package error instead of a container failure halfway through handling.

### Durable claim / dispatch / completion job and store

**Create:** `src/Webhooks/ProcessWebhookEventJob.php`, `Contracts/WebhookEventStore.php`, `DatabaseWebhookEventStore.php`, and the record model/value.

**Analogs:** `src/Sync/SyncHubspotObjectJob.php` lines 43-95 and `src/Registry/Stores/DatabaseAssociationTypeStore.php` lines 89-106, 182-206.

```php
final class SyncHubspotObjectJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(ModelBindings $bindings, PropertyMapper $mapper, ObjectGatewayContract $gateway): void
    {
        if (! App::make(SyncGate::class)->permits()) {
            Log::info(self::suppressedMessage(), [...]);
            return;
        }
        // Dependencies are method parameters, resolved per queue execution.
    }
}
```

```php
public function upsert(AssociationTypeRow $row): void
{
    $this->guarded(self::TABLE, function () use ($row): void {
        $this->rows()->updateOrInsert([...], [...]);
    });
}

private function guarded(string $table, callable $operation): mixed
{
    try {
        return $operation();
    } catch (QueryException $exception) {
        if ($this->connection->getSchemaBuilder()->hasTable($table)) {
            throw $exception;
        }

        throw ConfigurationException::missingRegistryTable($table);
    }
}
```

Use an atomic claim keyed by `event_id`, with a recoverable processing lease and a completed state. A completed record no-ops; an acquired/retryable record dispatches generic/typed/configured handlers; completion is written only after all succeed. A handler exception must escape the job so Laravel retries; it must not mark the record handled. Translate a missing enabled table to a new directed `ConfigurationException` factory, matching the guarded store pattern.

### Feature-gated migration and configuration

**Modify:** `config/hubspot.php`, `src/ServiceProvider.php`. **Create:** `database/migrations/webhooks/*_create_hubspot_webhook_events_table.php`.

**Analogs:** `config/hubspot.php` lines 351-372, `ServiceProvider.php` lines 196-214 and 277-282, and `database/migrations/sync/0001_01_01_000000_create_hubspot_object_links_table.php` lines 90-133.

```php
'webhooks' => [
    'enforce' => (bool) env('HUBSPOT_WEBHOOK_ENFORCE', true),
    'secret' => env('HUBSPOT_CLIENT_SECRET'),
    'tolerance' => 300,
],
```

```php
foreach ($this->migrationGroups() as $path => $active) {
    foreach (self::migrationFilesIn($path) as $file) {
        $publishable[$file] = $this->app->databasePath('migrations/'.basename($file));
    }

    if ($active) {
        $this->loadMigrationsFrom($path);
    }
}
```

```php
Schema::create('hubspot_object_links', function (Blueprint $table): void {
    $table->id();
    // columns
    $table->timestamps();
    $table->unique([...]);
    $table->index([...]);
});
```

Add a false-by-default `webhooks.enabled` flag and extend the existing `webhooks` section with handler, retention, desired-state, and app-model keys. Add `database/migrations/webhooks` as a third independently gated migration group; publishing remains unconditional. Keep comments explaining the security/default consequences. Preserve client-secret redaction: `ServiceProvider.php` lines 71-95 reads the secret only inside the ExceptionTranslator's on-demand resolver.

### Subscription and pruning commands

**Create:** `src/Webhooks/Console/SyncWebhookSubscriptionsCommand.php` and `PruneWebhookEventsCommand.php`.

**Analog:** `src/Registry/Console/SyncAssociationsCommand.php` lines 64-142 and 156-201.

```php
final class SyncAssociationsCommand extends Command
{
    protected $signature = 'hubspot:associations:sync';

    public function handle(AssociationTypeStore $store): int
    {
        $this->tally = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        try {
            $gateway = $this->laravel->make(AssociationDefinitionsGatewayContract::class);
            // Do work and report a deterministic tally.
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

Resolve potentially credentialed Gateway dependencies in `handle()`, not in constructors, to avoid breaking unrelated Artisan calls. `hubspot:webhooks:sync --dry-run` must validate declarations at command time, list/create/update declared legacy-public subscriptions, and report unmanaged extras without deleting. For legacy-private, print validated manual setup instructions; for project apps, emit/export the component. The prune command consumes configured retention and reports its deleted count.

### Fake assertion extension

**Modify:** `src/HubspotManager.php`, `src/Facades/Hubspot.php`, `src/Testing/HubspotFake.php`; likely add a dedicated in-memory receipt log/assertion helper.

**Analogs:** `HubspotManager.php` lines 101-164, `Facades/Hubspot.php` lines 72-97, `HubspotFake.php` lines 138-184.

```php
public function fake(array $responses = []): HubspotFake
{
    return $this->fake = new HubspotFake($this->container, $responses, $this->fake);
}

public function assertNothingSynced(): void
{
    $this->fakeOrFail()->assertNothingSynced();
}
```

```php
/** @method static void assertNothingSynced() */
final class Hubspot extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return HubspotManager::class;
    }
}
```

Implement `assertWebhookHandled` on its own inbound receipt log. Do **not** reuse `HubspotFake` Guzzle history: inbound receipt/queue handling does not traverse the outgoing SDK transport. Preserve `HubspotManager::flushState()` behavior so Octane cannot leak fake receipt state between entry points.

## Test Pattern Assignments

| Planned test area | Copy from | Concrete pattern |
|---|---|---|
| HTTP signature/URI/batch queue status tests | `tests/Feature/Sync/BatchSyncTest.php` and `tests/TestCase.php` | Laravel feature test plus `Bus::fake()`; assert one job per item and status, not merely controller invocation. |
| Event-store missing table, schema, claim/retry/prune | `tests/Feature/Registry/DatabaseStoreMissingTableTest.php`, `DatabaseStoreSchemaTest.php` | test the directed exception and actual persisted schema, not only a mocked store. |
| Job ordering/handler failures | `tests/Unit/Sync/HubspotObserverTest.php` | instantiate with `app(Dispatcher::class)` / resolve dependencies through the container and assert dispatch behavior. |
| Command create/update/extra/dry run | `tests/Feature/Registry/SyncAssociationsCommandTest.php` | execute through Artisan, fake the Gateway seam, assert deterministic console output and non-mutation on dry run. |
| fake assertion | `tests/Feature/Gateway/AssertAssociatedDirectionTest.php`, `tests/Feature/HubspotFakeTest.php` | test facade and returned fake assertion surfaces, including useful failure diagnostics. |
| Architecture/security regression | `tests/Arch/LayerBoundariesTest.php`, `tests/Arch/SdkSurfaceTest.php`, `tests/Arch/SecretLoggingTest.php` | add tests proving `Webhooks` does not import SDK types and payload/client secret are never logged. |

## Shared Patterns

### SDK and exception boundary

**Sources:** `src/Gateway/AssociationDefinitionsGateway.php` lines 66-79; `src/ServiceProvider.php` lines 71-95.  
**Apply to:** all Gateway verification/subscription adapters.

- Import SDK types only in Gateway.
- Catch SDK exceptions there and translate through `ExceptionTranslator`.
- Keep secrets out of object state and logs; derive redaction values only when an exception is constructed.

### Container lifetime and queue dependencies

**Sources:** `src/ServiceProvider.php` lines 160-165; `src/Sync/SyncHubspotObjectJob.php` lines 71-82.  
**Apply to:** webhook job, route collaborators, and Gateway bindings.

- Bind transport-sensitive gateways non-shared so `Hubspot::fake()` substitutions are observed.
- Resolve job dependencies as `handle()` parameters, not serialized constructor collaborators.

### Feature-gated persistence

**Sources:** `src/ServiceProvider.php` lines 196-214, 257-282; `src/Registry/Stores/DatabaseAssociationTypeStore.php` lines 182-206.  
**Apply to:** webhook audit/event store and its migration.

- Add a separate, opt-in migration directory; always publish it, only load it when enabled.
- Turn a genuinely absent opted-in table into a package exception naming the migration remedy.

### Public API / fake surface

**Sources:** `src/HubspotManager.php` lines 113-164; `src/Facades/Hubspot.php` lines 72-97.  
**Apply to:** `assertWebhookHandled`.

- Add behavior to the manager, expose its exact static signature in the facade docblock, and make the fake-returned object mirror the assertion.
- Keep inbound handling records separate from outgoing request history.

## No Analog Found

| File | Role | Data flow | Reason / planning instruction |
|---|---|---|---|
| `src/Webhooks/ProjectWebhookComponentExporter.php` | utility/service | transform, file-I/O | Package has no project-component exporter. Use a small package-owned immutable declaration/value and explicit rendered output tests; do not invent a runtime API mutation. |
| project webhook component template/fixture | config/template | file-I/O | No existing HubSpot project manifest/template. Keep it versioned in the package and validate its rendered output in tests. |
| legacy-private manual setup renderer | utility | transform | No analog because current commands only reconcile API-backed state. Follow console error/output conventions, but make this a deliberate manual-guidance artifact rather than a fake remote sync. |

## Metadata

**Analog search scope:** `src/Gateway`, `src/Registry`, `src/Sync`, `src/Testing`, `config`, `database/migrations`, `tests/Feature`, `tests/Unit`, `tests/Arch`  
**Strong analogs read:** `ServiceProvider`, `SyncHubspotObjectJob`, `DatabaseAssociationTypeStore`, `SyncAssociationsCommand`, `HubspotManager`, `HubspotFake`, `AssociationDefinitionsGateway`, and their contracts/config/migration seams.  
**Pattern extraction date:** 2026-08-06
