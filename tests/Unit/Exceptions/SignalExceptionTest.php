<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Unit\Exceptions;

use ReflectionClass;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Exceptions\SignalException;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;

/**
 * `SignalException`'s two factories (SIG-05, D-02, D-09), each asserted on the WHOLE message
 * rather than by substring -- see `ConfigurationExceptionTest`'s own docblock for why a substring
 * assertion cannot catch a reordered, truncated or dropped concatenation fragment.
 */
final class SignalExceptionTest extends TestCase
{
    public function test_it_implements_hubspot_exception_and_extends_runtime_exception(): void
    {
        $exception = SignalException::missingIdPropertyValue('App\\Models\\Contact', '1', 'email');

        self::assertInstanceOf(HubspotException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    public function test_the_class_is_final_and_constructible_only_through_its_named_factories(): void
    {
        $reflection = new ReflectionClass(SignalException::class);

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->getConstructor()?->isPublic());
    }

    public function test_visitor_already_bound_to_a_different_subject_names_both_bindings_and_the_fix(): void
    {
        $exception = SignalException::visitorAlreadyBoundToDifferentSubject(
            'visitor-1',
            'App\\Models\\Contact',
            '42',
            'App\\Models\\Lead',
            '7',
        );

        self::assertSame(
            'Visitor id "visitor-1" is already bound to App\Models\Contact #42, so it cannot '
            .'also be bound to App\Models\Lead #7 -- one visitor id may bind to only one '
            .'subject, though one subject may bind to many visitor ids (the same person on '
            .'several devices). Issue a fresh visitor id for the second person instead: '
            .'visitor-id issuance is the application\'s own responsibility, not this package\'s.',
            $exception->getMessage(),
        );
    }

    public function test_a_second_rebind_conflict_with_different_values_is_also_asserted_on_its_own_literal(): void
    {
        $exception = SignalException::visitorAlreadyBoundToDifferentSubject(
            'visitor-shared-device',
            'App\\Models\\Contact',
            '100',
            'App\\Models\\Contact',
            '200',
        );

        self::assertSame(
            'Visitor id "visitor-shared-device" is already bound to App\Models\Contact #100, so '
            .'it cannot also be bound to App\Models\Contact #200 -- one visitor id may bind to '
            .'only one subject, though one subject may bind to many visitor ids (the same '
            .'person on several devices). Issue a fresh visitor id for the second person '
            .'instead: visitor-id issuance is the application\'s own responsibility, not this '
            .'package\'s.',
            $exception->getMessage(),
        );
    }

    public function test_missing_id_property_value_names_the_subject_the_property_and_the_config_key(): void
    {
        $exception = SignalException::missingIdPropertyValue('App\\Models\\Contact', '42', 'email');

        self::assertSame(
            'App\Models\Contact #42 has no usable value for "email", the id_property '
            .'hubspot.models declares for it, so Hubspot::identify() cannot bind it to any '
            .'visitor id. Populate "email" on this subject before calling identify(), or '
            .'correct config(\'hubspot.models\')[\'App\Models\Contact\'][\'id_property\'] if a '
            .'different property should be used.',
            $exception->getMessage(),
        );
    }

    public function test_no_factory_signature_accepts_a_buffered_properties_payload(): void
    {
        $reflection = new ReflectionClass(SignalException::class);

        foreach (['visitorAlreadyBoundToDifferentSubject', 'missingIdPropertyValue'] as $factory) {
            foreach ($reflection->getMethod($factory)->getParameters() as $parameter) {
                self::assertNotSame('properties', $parameter->getName());
                self::assertSame('string', (string) $parameter->getType());
            }
        }
    }
}
