<?php

declare(strict_types=1);

/**
 * Phase 3 — the registry, the baseline map, and the definitions read, against a real portal.
 *
 * Three things here have never been exercised outside the fake:
 *
 * 1. **A seeded baseline id is real.** `contacts -> companies` is typeId 279 in this package's
 *    baseline because the design spec says so. This is where that number meets HubSpot: it is
 *    resolved offline with no network, put on the wire, and read back by SEARCHING the returned
 *    list for it. A wrong baseline id is the failure this package exists to prevent, and it is
 *    accepted silently by HubSpot -- so no test against a fake could ever catch it.
 *
 * 2. **`DefinitionsApi` lives where 03-CONTEXT says it does.** REG-02 originally named
 *    `Crm\Associations\V4\Api\DefinitionsApi`, which does not exist; the real class is under a
 *    `Schema` segment. That correction was made by reading the vendor tree. This calls it.
 *
 * 3. **HubSpot really does return `label: null` for HUBSPOT_DEFINED types.** The entire decision to
 *    give baseline rows this package's own canonical names rests on that being true. It was
 *    measured twice in FOUND-03 and never since.
 *
 * The read-back deliberately SEARCHES the returned association types rather than taking the first.
 * `associationTypes` comes back in no guaranteed order, so "the only one" or "the first one" would
 * report success regardless of which id was actually written -- the exact bug the doctor was
 * written to avoid.
 */

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Probes\Probe;
use ReyemTech\Hubspot\Registry\AssociationTypeRegistry;
use ReyemTech\Hubspot\Registry\Stores\ArrayAssociationTypeStore;

return function (Probe $p): void {
    $stamp = $p->stamp;

    // An EMPTY store. Everything resolved below comes from the seeded baseline read-through, which
    // is the claim being tested: a labelled write works straight after `composer require`, with no
    // sync, no database and no portal round trip to discover the id.
    $registry = new AssociationTypeRegistry(ArrayAssociationTypeStore::fromArray([]));

    $p->section('Phase 3 — the baseline resolves offline (REG-02)');

    $label = 'Contact to company';

    try {
        $resolved = $registry->resolve(
            new AssociationPair(from: new ObjectRef('contacts', '0'), to: new ObjectRef('companies', '0')),
            $label,
        );

        $resolved->typeId === 279
            ? $p->ok('resolved a baseline label with no network', "\"{$label}\" -> typeId 279")
            : $p->fail('resolved a baseline label', "expected typeId 279, got {$resolved->typeId}");
    } catch (AssociationTypeException $e) {
        $p->fail('resolved a baseline label with no network', $e->getMessage());

        return;
    }

    // The rule the whole phase exists to hold: a direction the registry does not know THROWS and
    // never answers with the inverse. `companies -> contacts` under this label is a different row
    // with a different id, and it must not be substituted.
    try {
        $registry->resolve(
            new AssociationPair(from: new ObjectRef('contacts', '0'), to: new ObjectRef('companies', '0')),
            'a label no portal has ever defined',
        );
        $p->fail('an unknown label throws', 'it RESOLVED -- a miss must never answer');
    } catch (AssociationTypeException $e) {
        $p->ok('an unknown label throws, naming the direction', 'no inverse substituted');
    }

    $p->section('Phase 3 — a labelled write reaches the wire with the right id (REG-02)');

    $contact = $p->objects->create('contacts', [
        'email' => "phase3-smoke-{$stamp}@example.com",
        'firstname' => 'Phase Three',
        'lastname' => 'Smoke',
    ]);
    $p->track('contacts', $contact->id);
    $p->ok('created a contact', "id {$contact->id}");

    $company = $p->objects->create('companies', ['name' => "Phase 3 smoke {$stamp}"]);
    $p->track('companies', $company->id);
    $p->ok('created a company', "id {$company->id}");

    $pair = new AssociationPair(
        from: new ObjectRef('contacts', $contact->id),
        to: new ObjectRef('companies', $company->id),
    );

    $p->associationsResolvedBy($registry)->associateWithLabel($pair, $label);
    $p->ok('labelled write accepted', 'resolved offline, sent typeId 279');

    // SEARCH the list, and FILTER IT TO THE RECORD WE CREATED FIRST. `read()` returns every
    // company associated with this contact, not just the one this pair names -- so portal
    // automation quietly associating the new contact with some existing company could contribute a
    // 279 of its own and make this pass while OUR association was never written (Codex P2 on
    // PR #34). Filtering by `toObjectId` is what makes the assertion about this pair.
    $rows = array_filter(
        $p->associations->read($pair),
        static fn ($r): bool => $r->toObjectId === $company->id,
    );
    $ids = array_values(array_map(static fn ($r): int => $r->typeId, $rows));

    // Membership is not enough. If a regression wrote BOTH 279 and the forbidden inverse 280,
    // HubSpot would accept both, `in_array(279, ...)` would still be true, and the inverse check
    // below would also pass because 280 is what it expects there -- so the probe would exit 0
    // having performed the exact inverse-direction write it exists to catch (Codex P2 on PR #34).
    // The forward direction must therefore also assert 280's ABSENCE.
    if (! in_array(279, $ids, true)) {
        $p->fail('read it back and found typeId 279', sprintf('got %s instead', implode(', ', $ids) ?: 'nothing'));
    } elseif (in_array(280, $ids, true)) {
        $p->fail(
            'read it back and found typeId 279 ALONE',
            sprintf('the inverse id 280 was written on the forward direction too: %s', implode(', ', $ids)),
        );
    } else {
        $p->ok('read it back and FOUND typeId 279', sprintf('among %d row(s): %s', count($rows), implode(', ', $ids)));
    }

    $inverseRows = array_filter(
        $p->associations->read($pair->reversed()),
        static fn ($r): bool => $r->toObjectId === $contact->id,
    );
    $inverseIds = array_values(array_map(static fn ($r): int => $r->typeId, $inverseRows));
    $p->ok('read the INVERSE direction', sprintf('typeId %s', implode(', ', $inverseIds) ?: 'none'));

    // `fail()`, not `note()`. This is the claim the section exists to make, and a note leaves the
    // process exiting 0 while the baseline's inverse is wrong or absent (Codex P2 on PR #34) -- a
    // probe that reports success having disproved its own premise is worse than no probe.
    if (in_array(280, $inverseIds, true) && in_array(279, $inverseIds, true)) {
        // The mirror of the check above: 279 has no business on the inverse direction.
        $p->fail(
            'the inverse is 280, as the baseline claims',
            sprintf('the forward id 279 was written on the inverse direction too: %s', implode(', ', $inverseIds)),
        );
    } elseif (in_array(280, $inverseIds, true)) {
        $p->ok('the inverse is 280, and 279 is absent', 'both directions confirmed live');
    } else {
        $p->fail(
            'the inverse is 280, as the baseline claims',
            'this portal reported '.(implode(', ', $inverseIds) ?: 'nothing')
            .' -- the seeded inverse_type_id is wrong or the association was not written. It is '
            .'recorded for traversal and never read on a write path, so nothing is mis-writing '
            .'today, but the baseline row is not what it says it is.',
        );
    }

    $p->section('Phase 3 — the definitions read, through the Schema-namespaced DefinitionsApi');

    $definitions = $p->definitions->listFor('contacts', 'companies');
    $p->ok('read this portal\'s definitions', sprintf('%d definition(s)', count($definitions)));

    $unlabelled = array_filter($definitions, static fn ($d): bool => $d->label === null);
    $labelled = array_filter($definitions, static fn ($d): bool => $d->label !== null);

    $p->ok(
        'counted labelled vs unlabelled',
        sprintf('%d with a label, %d with label: null', count($labelled), count($unlabelled)),
    );

    // Also `fail()`. The baseline gives HUBSPOT_DEFINED rows this package's OWN canonical names
    // solely because HubSpot returns no label for them; if that stops being true, the naming
    // decision needs revisiting and a green run would hide exactly that (Codex P2 on PR #34).
    // An empty response fails here too -- nothing read is not the same as the invariant holding.
    if ($definitions === []) {
        $p->fail(
            'HubSpot really does return label: null',
            'the definitions read returned nothing at all, so the invariant was not observed',
        );
    } elseif (count($unlabelled) > 0) {
        $p->ok('HubSpot really does return label: null', 'the baseline naming decision holds');
    } else {
        $p->fail(
            'HubSpot really does return label: null',
            sprintf(
                'all %d definition(s) carried a label. The baseline names HUBSPOT_DEFINED rows '
                .'itself precisely because FOUND-03 measured null labels twice; that premise no '
                .'longer holds and the decision needs revisiting.',
                count($definitions),
            ),
        );
    }

    $p->associationsResolvedBy($registry)->dissociate($pair);
    $p->ok('dissociated', '');
};
