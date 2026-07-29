<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Registry\Console;

use Illuminate\Console\Command;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\AssociationType;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationGatewayContract;
use ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Registry\AssociationDirection;
use ReyemTech\Hubspot\Registry\AssociationTypeRow;
use ReyemTech\Hubspot\Registry\Contracts\AssociationTypeStore;
use ReyemTech\Hubspot\Registry\HubspotObjectType;

/**
 * **Does the direction this package believes in actually hold in the portal?**
 *
 * Given two real records and the label each direction carries, it reads both directions back and
 * reports, per direction, whether the type id the registry would send is really there.
 *
 * ## It SEARCHES. It never takes the first, and never takes "the only one"
 *
 * This is the rule the whole command exists for, and the one `Gateway\AssociationRow::$typeId`'s
 * deferred-item entry was written to impose on its second consumer.
 *
 * An association read returns a LIST of association types per related record, in an order HubSpot
 * does not guarantee — FOUND-03 observed one record carrying both a `USER_DEFINED` label and
 * HubSpot's own `HUBSPOT_DEFINED` default together. Reading `$rows[0]->typeId` would therefore report
 * success regardless of which id was actually written, which would make this command **certify the
 * exact defect the package exists to prevent**. {@see self::probe()} therefore decides with a strict
 * `in_array()` over every id reported for the record in question, and the report names all of them so
 * an operator can see what else is on the record.
 *
 * ## What it records, and what it refuses to record
 *
 * `inverse_type_id` cannot be derived from a definitions read: the two directions' responses share no
 * join key (see {@see SyncAssociationsCommand}). **Observation is its one honest source**, and this
 * is the observation.
 *
 * The operator names the two labels they believe are a pair — they have to, because a paired HubSpot
 * label carries a different name in each direction, so neither is derivable from the other. This
 * command then CONFIRMS the pairing rather than deriving it: it checks that each direction's expected
 * id is really present in that direction's own read, and only if both are does the pairing reach the
 * registry, each direction recording the other's observed id.
 *
 * If either direction fails to materialise, **nothing is written at all**. A half-observed pairing is
 * a guess wearing a measurement's clothes, and the guessed value would be a real, valid, wrong
 * association id that HubSpot accepts without complaint.
 *
 * Note what it never does: it never invents an id. Every id it writes was either read from the portal
 * or resolved from the registry — never computed, never carried across a direction.
 */
final class AssociationsDoctorCommand extends Command
{
    protected $signature = 'hubspot:associations:doctor
        {from : the from side\'s object type}
        {from-id : the from side\'s record id}
        {to : the to side\'s object type}
        {to-id : the to side\'s record id}
        {--label= : the label the from -> to direction carries}
        {--inverse-label= : the label the to -> from direction carries}';

    protected $description = 'Probe one association in both directions and report which type ids materialised';

    public function handle(AssociationTypeStore $store): int
    {
        $label = $this->stringOption('label');
        $inverseLabel = $this->stringOption('inverse-label');

        if ($label === null || $inverseLabel === null) {
            $this->error(
                'Both --label and --inverse-label are required. A paired HubSpot label carries a '
                .'different name in each direction, so neither can be derived from the other.'
            );

            return self::FAILURE;
        }

        try {
            // Normalised BEFORE anything is built, so the request path and the registry lookup use
            // one spelling. `AssociationGateway` builds the request URI from the pair's own object
            // types, so an accepted alias reaching the pair unnormalised would resolve a row and then
            // address `/crm/v4/objects/Deal/...` — a 404 about a route rather than an error about the
            // argument (Codex P1 on PR #24).
            $pair = new AssociationPair(
                from: new ObjectRef(HubspotObjectType::normalise($this->argument('from'))->value, $this->argument('from-id')),
                to: new ObjectRef(HubspotObjectType::normalise($this->argument('to'))->value, $this->argument('to-id')),
            );

            // Both expected ids resolve before either read, so an unregistered label reports what is
            // missing without first issuing a probe that could prove nothing.
            $resolver = $this->laravel->make(AssociationTypeResolver::class);
            $forwardType = $resolver->resolve($pair, $label);
            $inverseType = $resolver->resolve($pair->reversed(), $inverseLabel);

            $gateway = $this->laravel->make(AssociationGatewayContract::class);

            $forwardFound = $this->probe($gateway, $pair, $forwardType, $label);
            $inverseFound = $this->probe($gateway, $pair->reversed(), $inverseType, $inverseLabel);
        } catch (HubspotException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $forwardFound || ! $inverseFound) {
            $this->line('Recorded nothing: a pairing is recorded only when both directions were observed.');

            return self::FAILURE;
        }

        $this->record($store, $pair, $forwardType, $label, $inverseType->typeId);
        $this->record($store, $pair->reversed(), $inverseType, $inverseLabel, $forwardType->typeId);

        $this->line(sprintf(
            'Recorded the observed pairing: %s #%d inverse #%d, %s #%d inverse #%d.',
            self::directionOf($pair)->describe(),
            $forwardType->typeId,
            $inverseType->typeId,
            self::directionOf($pair->reversed())->describe(),
            $inverseType->typeId,
            $forwardType->typeId,
        ));

        return self::SUCCESS;
    }

    /**
     * Reads one direction and reports whether the expected id is among the types HubSpot returned for
     * the record on the other end.
     */
    private function probe(
        AssociationGatewayContract $gateway,
        AssociationPair $pair,
        AssociationType $expected,
        string $label,
    ): bool {
        // The read lists everything the from record is associated to of the to side's object type, so
        // the rows are filtered down to the record this probe is actually about. Skipping the filter
        // would let an unrelated record's matching id report success for this one.
        $reported = [];

        foreach ($gateway->read($pair) as $row) {
            if ($row->toObjectId === $pair->to->id) {
                $reported[] = $row->typeId;
            }
        }

        $subject = sprintf('%s %s -> %s %s', $pair->from->objectType, $pair->from->id, $pair->to->objectType, $pair->to->id);

        if ($reported === []) {
            $this->line(sprintf(
                '%s: no association with %s %s was reported in this direction at all.',
                $subject,
                $pair->to->objectType,
                $pair->to->id,
            ));

            return false;
        }

        // **Search, never take.** `in_array` over every reported id, strictly — not `$reported[0]`,
        // and not `count($reported) === 1`. See this class's docblock for what taking the first would
        // certify.
        $found = in_array($expected->typeId, $reported, true);

        $this->line(sprintf(
            '%s: type id %d for label "%s" %s among %d reported: %s.',
            $subject,
            $expected->typeId,
            $label,
            $found ? 'FOUND' : 'NOT FOUND',
            count($reported),
            implode(', ', array_map(strval(...), $reported)),
        ));

        return $found;
    }

    /**
     * Writes one direction's row back with the observed inverse id on it.
     *
     * The type and the label are the ones already held — this command changes neither. Only
     * `inverse_type_id` moves, from null to the id the OTHER direction was actually observed to
     * carry. `is_default` is carried across from the existing row rather than defaulted, because this
     * probe measures nothing about it and overwriting a known value with null would lose a fact.
     */
    private function record(
        AssociationTypeStore $store,
        AssociationPair $pair,
        AssociationType $type,
        string $label,
        int $observedInverseTypeId,
    ): void {
        $direction = self::directionOf($pair);

        $store->upsert(new AssociationTypeRow(
            direction: $direction,
            type: $type,
            label: $label,
            inverseTypeId: $observedInverseTypeId,
            isDefault: $store->resolve($direction, $label)?->isDefault,
        ));
    }

    private static function directionOf(AssociationPair $pair): AssociationDirection
    {
        return AssociationDirection::of(from: $pair->from->objectType, to: $pair->to->objectType);
    }

    /**
     * An option the operator actually supplied, or null.
     *
     * An empty string counts as absent: `--label=` is a mistake rather than a request to look for a
     * label spelled `''`, and the directed message about supplying both is a better answer than a
     * registry miss on an empty label.
     */
    private function stringOption(string $name): ?string
    {
        /** @var mixed $value */
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
