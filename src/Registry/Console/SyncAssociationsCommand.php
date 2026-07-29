<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\AssociationDefinition;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationDefinitionsGatewayContract;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;

/**
 * **Reconciles a portal's own association labels into the registry.**
 *
 * The seeded baseline covers HubSpot-defined types only, and every portal's `USER_DEFINED` label
 * carries a portal-specific id — design spec §6.2: "your `partner_agency` id is a different integer
 * in another account". Without this command the registry is permanently incomplete for exactly the
 * labels teams use.
 *
 * ## Two reads per pair, and never one
 *
 * `DefinitionsApi::getPage($from, $to)` answers for ONE direction. Reconciling a pair therefore issues
 * two reads, and each direction's rows are built from that direction's own response. A sync that read
 * once and wrote both directions would be the wrong-direction defect this package exists to prevent,
 * arriving through a reconciliation routine instead of through a write.
 *
 * That is not a stylistic preference: a paired label is asymmetric in its NAME as well as in its id.
 * FOUND-03 run 2 measured `Deals` on the forward direction and `People` on the inverse, so the forward
 * response does not even contain the reverse direction's label text.
 *
 * ## `inverse_type_id` is left null, and that is the correct value
 *
 * Every row written here has a null inverse id. It is not a gap waiting to be filled in by inference,
 * because **the two directional responses share no join key**. Each returned item carries `category`,
 * `label` and `type_id` and nothing else, and the labels differ by name across directions — so given a
 * forward list `[1: "Deals", 5: "Sponsor"]` and a reverse list `[2: "People", 6: "Sponsored by"]`,
 * nothing in either response says which pairs with which. Matching by array position, or by "the only
 * other user-defined one", would silently persist another label's id as the inverse — a real, valid,
 * wrong association id that HubSpot accepts without complaint (Codex P1 on PR #22).
 *
 * **No richer endpoint exposes the pairing.** Checked in the pinned 14.1.0 rather than assumed:
 * `Schema\Model\PublicAssociationDefinitionCreateRequest` carries an `inverseLabel`, so HubSpot knows
 * the pairing at CREATE time — but no read model returns it. `AssociationSpecWithLabel` (this read)
 * and `PublicAssociationDefinitionUserConfiguration` (`DefinitionConfigurationsApi`) both carry
 * `category`, `label` and `typeId` only. The one honest source is observation, which is
 * `hubspot:associations:doctor`'s job.
 *
 * ## Definitions with no label of their own are skipped
 *
 * HubSpot returns `label: null` for its own `HUBSPOT_DEFINED` types (measured twice in FOUND-03).
 * Those are counted, reported and not written. Two reasons, and the second is the decisive one:
 * the registry's only read takes a NON-NULLABLE label and the unlabelled write path consults the
 * registry not at all (design spec §6.1 rule 3), so such a row is unreachable by every consumer this
 * package has — and a direction with two HubSpot-defined types would give both the identical
 * `default:` storage key, so the second would silently overwrite the first. Rows nobody can read that
 * overwrite each other are worse than no rows.
 */
final class SyncAssociationsCommand extends Command
{
    protected $signature = 'hubspot:associations:sync';

    protected $description = "Reconcile this portal's association labels into the type registry";

    /**
     * Outcome tallies for the CURRENT run, reported at the end so an operator can see at a glance
     * whether it changed anything. A command that printed only "done" gives nobody a way to notice it
     * wrote something surprising.
     *
     * @var array<string, int>
     */
    private array $tally = [];

    public function handle(AssociationTypeStore $store): int
    {
        // Reset per run, not initialised as a property default. Artisan resolves one command instance
        // and reuses it, so a second `Artisan::call('hubspot:associations:sync')` in the same process
        // — a scheduler tick, a queued job, a test — would otherwise report the first run's additions
        // again on top of its own, telling an operator rows had been written that had not.
        $this->tally = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        $pairs = $this->enabledPairs();

        if ($pairs === []) {
            $this->error(
                'No association pairs are enabled for reconciliation. Add at least one to the '
                .'`associations.sync` key of config/hubspot.php, for example '
                .'["from" => "deals", "to" => "contacts"], then run this command again.'
            );

            return self::FAILURE;
        }

        // Resolved here rather than injected into the constructor: building the gateway builds the
        // HTTP client, which throws `ConfigurationException::missingToken()` when HUBSPOT_TOKEN is
        // unset — and a constructor-injected gateway would raise that while the console kernel was
        // registering commands, i.e. for every artisan invocation in an application that has this
        // package installed but no token yet.
        try {
            $gateway = $this->laravel->make(AssociationDefinitionsGatewayContract::class);

            $directions = 0;

            foreach ($pairs as $pair) {
                foreach ($this->directionsOf($pair) as $direction) {
                    $this->reconcile($gateway, $store, $direction);
                    $directions++;
                }
            }
        } catch (HubspotException $exception) {
            // The package's own hierarchy, never a raw SDK or Guzzle failure (STANDARDS §9). Every
            // member's message names the fix, so printing it is the whole of the report.
            //
            // `HubspotException` is a package-owned INTERFACE rather than a base class, so that
            // consumers can catch one type while each member still extends the SPL exception that
            // best describes its failure mode. PHP only permits catching a type that is
            // `Throwable`-compatible, so `getMessage()` is reachable here and PHPStan at level max
            // narrows the caught value to `HubspotException&Throwable` and proves it.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // Only after every read succeeded. A failed run that recorded a reconciliation would have
        // `hubspot:doctor` report a freshly synced registry when nothing was read.
        $store->markReconciled(Carbon::now()->toDateTimeImmutable());

        $this->line(sprintf(
            'Reconciled %d directions: %d added, %d updated, %d unchanged, %d skipped.',
            $directions,
            $this->tally['added'],
            $this->tally['updated'],
            $this->tally['unchanged'],
            $this->tally['skipped'],
        ));

        return self::SUCCESS;
    }

    /**
     * The pairs `config('hubspot.associations.sync')` names.
     *
     * The keys are deliberately not normalised away. Nothing here reads one — the pairs are only
     * iterated — so a consumer who would rather name their entries
     * (`'deals-to-contacts' => ['from' => ..., 'to' => ...]`) gets exactly the same behaviour as one
     * who writes a plain list. An `array_values()` here would have been a distinction no caller can
     * observe, which is a line no test can justify.
     *
     * @return array<array-key, mixed>
     */
    private function enabledPairs(): array
    {
        /** @var mixed $configured */
        $configured = $this->laravel->make('config')->get('hubspot.associations.sync');

        return is_array($configured) ? $configured : [];
    }

    /**
     * A configured pair's two directions, in the order they are read.
     *
     * The entry names `from` and `to` and BOTH directions are reconciled from it, because a label
     * defined between two object types has a row on each side and neither is derivable from the
     * other. The naming is not decorative: it fixes the order the report prints in, so two runs
     * against the same config are diffable.
     *
     * Nothing is validated here. `AssociationDirection::of()` normalises through
     * `HubspotObjectType`, which raises this package's own `ObjectTypeException` naming the offending
     * value — a malformed entry therefore reports what is wrong with it rather than producing a
     * request against a real-looking path HubSpot answers with a 404 about a route.
     *
     * @return list<AssociationDirection>
     */
    private function directionsOf(mixed $pair): array
    {
        $from = is_array($pair) ? ($pair['from'] ?? null) : null;
        $to = is_array($pair) ? ($pair['to'] ?? null) : null;

        return [
            AssociationDirection::of(from: $from, to: $to),
            AssociationDirection::of(from: $to, to: $from),
        ];
    }

    /**
     * One direction: read it, and write what it reported.
     */
    private function reconcile(
        AssociationDefinitionsGatewayContract $gateway,
        AssociationTypeStore $store,
        AssociationDirection $direction,
    ): void {
        $definitions = $gateway->listFor(
            fromObjectType: $direction->from->value,
            toObjectType: $direction->to->value,
        );

        $unlabelled = 0;

        foreach ($definitions as $definition) {
            if ($definition->label === null) {
                $unlabelled++;

                continue;
            }

            $this->record($store, $direction, $definition, $definition->label);
        }

        if ($unlabelled > 0) {
            $this->tally['skipped'] += $unlabelled;

            $this->line(sprintf(
                '%s: skipped %d %s HubSpot returned with no label of their own',
                $direction->describe(),
                $unlabelled,
                $unlabelled === 1 ? 'definition' : 'definitions',
            ));
        }
    }

    /**
     * Writes one row and reports what changed.
     *
     * The existing row is read through the store, which reads through to the seeded baseline — so a
     * portal label spelled identically to a seeded one is reported as an UPDATE naming both ids and
     * both categories rather than silently replacing a HubSpot-defined default. That is the whole of
     * "reconciliation never overwrites a baseline id without saying so": the override itself is
     * correct, since the portal's own id is the one HubSpot will honour, and it is the silence that
     * would not be.
     *
     * ## What an UNCHANGED row keeps, and why (Codex P2 on PR #28)
     *
     * `inverse_type_id` and `is_default` are **carried across when the type is unchanged**. This
     * command cannot produce either value — see the class docblock for why the pairing is not
     * derivable — so the only way a row has one is that `hubspot:associations:doctor` observed it,
     * which costs a real association on a real record pair in a real portal.
     *
     * Rewriting them to null on every re-read would silently throw that measurement away, and the
     * report would say `unchanged` while it happened, so nobody would know to look. An operator would
     * have to re-run the doctor after every reconciliation to get back to where they already were.
     *
     * They are cleared when the type actually **changes**, and that is not symmetry for its own sake:
     * the doctor's observation was about one specific type id. If the portal now reports a different
     * one for this label, whatever was measured was measured about a type this direction no longer
     * uses, and keeping it would leave a stale inverse id attached to a new type — a number that
     * looks verified and is not.
     */
    private function record(
        AssociationTypeStore $store,
        AssociationDirection $direction,
        AssociationDefinition $definition,
        string $label,
    ): void {
        $existing = $store->resolve($direction, $label);
        $unchanged = $existing !== null && self::isSameType($existing->type, $definition->type);

        $store->upsert(new AssociationTypeRow(
            direction: $direction,
            type: $definition->type,
            label: $label,
            // Never derived from this read, and never carried over from the other direction's — only
            // ever kept from an observation this row already carried for this same type.
            inverseTypeId: $unchanged ? $existing->inverseTypeId : null,
            // HubSpot's labels endpoint does not report which type an unlabelled write materialises,
            // so this is never guessed at from the category — only kept, on the same terms.
            isDefault: $unchanged ? $existing->isDefault : null,
        ));

        if ($existing === null) {
            $this->tally['added']++;
            $this->line(sprintf(
                '%s: added %s',
                $direction->describe(),
                self::describe($label, $definition->type),
            ));

            return;
        }

        if (self::isSameType($existing->type, $definition->type)) {
            $this->tally['unchanged']++;
            $this->line(sprintf(
                '%s: unchanged %s',
                $direction->describe(),
                self::describe($label, $definition->type),
            ));

            return;
        }

        $this->tally['updated']++;
        $this->line(sprintf(
            '%s: updated "%s" #%d (%s) -> #%d (%s)',
            $direction->describe(),
            $label,
            $existing->type->typeId,
            $existing->type->category->value,
            $definition->type->typeId,
            $definition->type->category->value,
        ));
    }

    private static function isSameType(AssociationType $existing, AssociationType $read): bool
    {
        return $existing->typeId === $read->typeId && $existing->category === $read->category;
    }

    private static function describe(string $label, AssociationType $type): string
    {
        return sprintf('"%s" #%d (%s)', $label, $type->typeId, $type->category->value);
    }
}
