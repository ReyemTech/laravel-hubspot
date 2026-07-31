<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Support\Sync;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;

/**
 * {@see SyncTestCase} plus a second binding for {@see NarrowedLead}, the model that declares
 * `$hubspotUpdateMap`.
 *
 * A subclass rather than an extra entry on `SyncTestCase` itself: that case is shared by every
 * Sync feature test, and a later plan asserting the exact shape of `hubspot.models` would be
 * silently reading a binding it did not put there. Extending keeps the addition visible to the one
 * test that needs it.
 */
class NarrowedSyncTestCase extends SyncTestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var ConfigRepository $config */
        $config = $app->make('config');

        /** @var array<class-string, array<string, string>> $models */
        $models = $config->get('hubspot.models', []);

        $models[NarrowedLead::class] = [
            'object' => 'contacts',
            'id_property' => 'email',
        ];

        $config->set('hubspot.models', $models);
    }
}
