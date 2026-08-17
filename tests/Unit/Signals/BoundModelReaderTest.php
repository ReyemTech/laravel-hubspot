<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Signals;

use Illuminate\Config\Repository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Signals\BoundModelReader;
use ReyemTech\Hubspot\Signals\BoundSignalSubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalCompanySubject;
use ReyemTech\Hubspot\Tests\Support\Signals\SignalSubject;
use ReyemTech\Hubspot\Tests\TestCase;

mutates(BoundModelReader::class);

/**
 * D-01: `Signals`' own `hubspot.models` reader. Unit-tested directly against a plain
 * `Illuminate\Config\Repository`, with no application booted -- this class needs nothing more.
 */
final class BoundModelReaderTest extends TestCase
{
    private function reader(): BoundModelReader
    {
        return new BoundModelReader(new Repository([
            'hubspot' => [
                'models' => [
                    SignalSubject::class => ['object' => 'contacts', 'id_property' => 'email'],
                    SignalCompanySubject::class => ['object' => 'companies', 'id_property' => 'domain'],
                ],
            ],
        ]));
    }

    public function test_all_resolves_every_configured_binding(): void
    {
        $bindings = $this->reader()->all();

        self::assertEquals(new BoundSignalSubject('contacts', 'email'), $bindings[SignalSubject::class]);
        self::assertEquals(new BoundSignalSubject('companies', 'domain'), $bindings[SignalCompanySubject::class]);
    }

    public function test_for_resolves_one_bindings_binding(): void
    {
        $binding = $this->reader()->for(SignalSubject::class);

        self::assertSame('contacts', $binding->objectType);
        self::assertSame('email', $binding->idProperty);
    }

    public function test_for_throws_a_directed_exception_for_an_unbound_class(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'stdClass was passed to Hubspot::identify() but has no entry in hubspot.models.',
        );

        $this->reader()->for(\stdClass::class);
    }

    public function test_claims_object_type_is_true_for_a_bound_object_type(): void
    {
        self::assertTrue($this->reader()->claimsObjectType('contacts'));
        self::assertTrue($this->reader()->claimsObjectType('companies'));
    }

    public function test_claims_object_type_is_false_for_an_unbound_object_type(): void
    {
        self::assertFalse($this->reader()->claimsObjectType('deals'));
    }
}
