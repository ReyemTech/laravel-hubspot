<?php

declare(strict_types=1);

/**
 * Phase 2 — the Gateway layer, against a real portal.
 *
 * Steps 6 to 8 are the ones that matter. HubSpot maintains the inverse association itself, with its
 * OWN distinct typeId, and the pair measured here (`deals -> contacts` = 3, `contacts -> deals` = 4)
 * is the fact the whole package is built around. A library that assumed symmetry would write one
 * where the other belonged, HubSpot would accept it without complaint, and nobody would notice for
 * months. That is why it is measured here rather than asserted against a fixture that could have
 * been written to agree with the code.
 */

use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Gateway\AssociationPair;
use ReyemTech\Hubspot\Gateway\ObjectRef;
use ReyemTech\Hubspot\Gateway\SearchQuery;
use ReyemTech\Hubspot\Probes\Probe;

return function (Probe $p): void {
    $stamp = $p->stamp;
    $email = "phase2-smoke-{$stamp}@example.com";

    $p->section('Phase 2 — generic object core (GW-01): one class, many object types');

    // Declared BEFORE the creates, so the cleanup sweep still covers these types if a create
    // commits and then loses its response -- the one case `track()` cannot reach.
    $p->willCreate('contacts');
    $p->willCreate('deals');

    // `.test` is reserved by RFC 2606 but HubSpot's own email validation rejects it with a 400.
    // Measured, not assumed -- it is what made the first run of this script fail.
    $contact = $p->objects->create('contacts', [
        'email' => $email,
        'firstname' => 'Phase Two',
        'lastname' => 'Smoke',
    ]);
    $p->track('contacts', $contact->id);
    $p->ok('created a contact', "id {$contact->id}");

    // Same class, different object type -- no per-type service exists anywhere in src/.
    $deal = $p->objects->create('deals', [
        'dealname' => "Phase 2 smoke {$stamp}",
        'pipeline' => 'default',
    ]);
    $p->track('deals', $deal->id);
    $p->ok('created a deal through the same class', "id {$deal->id}");

    $found = $p->objects->find('contacts', $contact->id, ['email', 'firstname']);
    $foundEmail = (string) ($found->properties['email'] ?? '?');

    $foundEmail === $email
        ? $p->ok('found it back', $foundEmail)
        : $p->fail('found it back', "expected {$email}, got {$foundEmail}");

    // Read back, rather than trusting a 200. An update that is accepted while sending an empty or
    // wrong value would otherwise report success having verified nothing -- and the search below
    // asks only for the email, so nothing downstream would notice either (Codex P2, PR #34).
    $p->objects->update('contacts', $contact->id, ['lastname' => 'Smoked']);
    $updated = $p->objects->find('contacts', $contact->id, ['lastname']);
    $updatedLastName = (string) ($updated->properties['lastname'] ?? '');

    $updatedLastName === 'Smoked'
        ? $p->ok('updated it', 'lastname => Smoked, confirmed by reading it back')
        : $p->fail('updated it', sprintf('expected lastname "Smoked", read back "%s"', $updatedLastName));

    // Retried, because HubSpot's search index is eventually consistent: a contact created seconds
    // ago can legitimately return zero results while create, find and update have all succeeded
    // (Codex P2 on PR #34). Asserting on the first attempt would fail a perfectly healthy gateway
    // on any portal whose indexing lags -- a flaky probe that cries wolf is one nobody re-runs.
    $attempts = 0;
    $results = 0;

    do {
        $attempts++;

        if ($attempts > 1) {
            sleep(2);
        }

        $results = count($p->objects->search('contacts', SearchQuery::make()->where('email', 'EQ', $email))->results);
    } while ($results !== 1 && $attempts < 5);

    $results === 1
        ? $p->ok('searched for it', sprintf('1 result after %d attempt(s)', $attempts))
        : $p->fail('searched for it', sprintf('expected 1 result, got %d after %d attempts', $results, $attempts));

    $p->section('Phase 2 — directional associations (GW-02): the reason this package exists');

    $dealToContact = new AssociationPair(
        from: new ObjectRef('deals', $deal->id),
        to: new ObjectRef('contacts', $contact->id),
    );

    $p->associations->associate($dealToContact);
    $p->ok('associated deal -> contact (unlabelled)', 'no typeId sent at all');

    // Filtered to the records this probe created. `read()` returns every contact associated with
    // the deal, so an unrelated association -- portal automation, a workflow -- could otherwise
    // contribute type ids that make the assertions below pass for the wrong reason.
    $forward = array_filter(
        $p->associations->read($dealToContact),
        static fn ($r): bool => $r->toObjectId === $contact->id,
    );
    $forwardIds = array_values(array_map(static fn ($r): int => $r->typeId, $forward));
    $p->ok('read it back', sprintf('%d row(s): typeId %s', count($forward), implode(', ', $forwardIds)));

    $inverse = array_filter(
        $p->associations->read($dealToContact->reversed()),
        static fn ($r): bool => $r->toObjectId === $deal->id,
    );
    $inverseIds = array_values(array_map(static fn ($r): int => $r->typeId, $inverse));
    $p->ok('read the INVERSE direction', sprintf('%d row(s): typeId %s', count($inverse), implode(', ', $inverseIds)));

    // The assertion, not just the display. Emptiness is checked FIRST and separately, because
    // `array_intersect([], []) === []` is true -- so a pair of empty reads would have reported
    // "asymmetry confirmed" while proving nothing at all, and the probe would still have exited 0
    // (Codex P2 on PR #34). A disjointness test over sets that may be empty is vacuous, which is
    // exactly the defect this file elsewhere accuses "take the first row" of being.
    if ($forwardIds === [] || $inverseIds === []) {
        $p->fail(
            'the two directions carry DIFFERENT type ids',
            sprintf(
                'nothing to compare: forward returned %d row(s), inverse %d',
                count($forwardIds),
                count($inverseIds),
            ),
        );
    } elseif (array_intersect($forwardIds, $inverseIds) !== []) {
        $p->fail(
            'the two directions carry DIFFERENT type ids',
            sprintf('forward %s and inverse %s OVERLAP', implode(',', $forwardIds), implode(',', $inverseIds)),
        );
    } else {
        $p->ok('the two directions carry DIFFERENT type ids', 'asymmetry confirmed live');
    }

    // And the specific documented pair, not merely "some two different numbers". FOUND-03 measured
    // 3 forward and 4 inverse for this pair, and the design documents say so; a portal disagreeing
    // means those documents are wrong, which is worth a red rather than a shrug.
    in_array(3, $forwardIds, true) && in_array(4, $inverseIds, true)
        ? $p->ok('and they are the documented 3 and 4', 'FOUND-03 confirmed')
        : $p->fail(
            'and they are the documented 3 and 4',
            sprintf(
                'FOUND-03 measured 3 forward and 4 inverse; this portal reported %s and %s',
                implode(',', $forwardIds) ?: 'nothing',
                implode(',', $inverseIds) ?: 'nothing',
            ),
        );

    $p->note(
        'Different typeIds in each direction is the whole point. A package that assumed symmetry '
        .'would write the wrong one, HubSpot would accept it, and nobody would notice.'
    );

    $p->section('Phase 2 — the guarantee: no registry means it THROWS, never guesses (GW-02)');

    try {
        $p->associations->associateWithLabel($dealToContact, 'Decision maker');
        $p->fail('labelled write refused', 'it was ACCEPTED -- a labelled write must be impossible with no registry bound');
    } catch (AssociationTypeException $e) {
        $p->ok('labelled write refused', 'and zero requests were issued');
        $p->note($e->getMessage());
    }

    // Read back. `dissociate()` returns void, and HubSpot accepts a delete for an association that
    // is not there as an idempotent no-op -- so an unconditional ok() would pass whether or not
    // anything was removed, and nothing later reads this pair again (Codex P2, PR #34).
    $p->associations->dissociate($dealToContact);

    $remaining = array_values(array_filter(
        $p->associations->read($dealToContact),
        static fn ($r): bool => $r->toObjectId === $contact->id,
    ));

    $remaining === []
        ? $p->ok('dissociated that one direction', 'confirmed by reading the pair back')
        : $p->fail(
            'dissociated that one direction',
            sprintf('the association is still present: typeId %s', implode(', ', array_map(
                static fn ($r): int => $r->typeId,
                $remaining,
            ))),
        );
};
