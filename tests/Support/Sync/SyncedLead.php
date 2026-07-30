<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Database\Eloquent\Model;
use ReyemTech\Hubspot\Sync\SyncsToHubspot;

/**
 * The tracer's one bound model. Table and schema are defined by {@see SyncTestCase}, not a
 * migration of its own -- this is a consumer's application model, not a package artifact.
 *
 * `$hubspotMap` carries exactly two literal entries on purpose: `email`, because the binding's
 * `id_property` is `email` and the upsert's id value has to come from the resolved property bag,
 * and `firstname`, so the test proves a SECOND property travels alongside the id rather than only
 * the id field itself. The dot-notation and closure forms `PropertyMapper` will grow are 04-03's;
 * a tracer proves one path end to end, not every form of it.
 */
final class SyncedLead extends Model
{
    use SyncsToHubspot;

    protected $table = 'synced_leads';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $hubspotMap = [
        'email' => 'email',
        'firstname' => 'first_name',
    ];
}
