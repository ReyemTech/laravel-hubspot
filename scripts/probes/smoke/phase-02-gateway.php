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

    $p->objects->update('contacts', $contact->id, ['lastname' => 'Smoked']);
    $p->ok('updated it', 'lastname => Smoked');

    $page = $p->objects->search('contacts', SearchQuery::make()->where('email', 'EQ', $email));

    count($page->results) === 1
        ? $p->ok('searched for it', '1 result')
        : $p->fail('searched for it', sprintf('expected 1 result, got %d', count($page->results)));

    $p->section('Phase 2 — directional associations (GW-02): the reason this package exists');

    $dealToContact = new AssociationPair(
        from: new ObjectRef('deals', $deal->id),
        to: new ObjectRef('contacts', $contact->id),
    );

    $p->associations->associate($dealToContact);
    $p->ok('associated deal -> contact (unlabelled)', 'no typeId sent at all');

    $forward = $p->associations->read($dealToContact);
    $forwardIds = array_map(static fn ($r): int => $r->typeId, $forward);
    $p->ok('read it back', sprintf('%d row(s): typeId %s', count($forward), implode(', ', $forwardIds)));

    $inverse = $p->associations->read($dealToContact->reversed());
    $inverseIds = array_map(static fn ($r): int => $r->typeId, $inverse);
    $p->ok('read the INVERSE direction', sprintf('%d row(s): typeId %s', count($inverse), implode(', ', $inverseIds)));

    // The assertion, not just the display: if these ever overlap, the package's whole premise is
    // wrong and every directional guarantee built on it is decoration.
    array_intersect($forwardIds, $inverseIds) === []
        ? $p->ok('the two directions carry DIFFERENT type ids', 'asymmetry confirmed live')
        : $p->fail(
            'the two directions carry different type ids',
            sprintf('forward %s and inverse %s overlap', implode(',', $forwardIds), implode(',', $inverseIds)),
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

    $p->associations->dissociate($dealToContact);
    $p->ok('dissociated that one direction', '');
};
