<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use Composer\Autoload\ClassLoader;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Gateway\ExceptionTranslator;
use ReyemTech\Hubspot\Tests\TestCase;
use RuntimeException;

/**
 * Task 1 (02-02): completes the typed exception hierarchy to all four members rooted at
 * `HubspotException` (STANDARDS §9, design spec §9). Every message names the fix, not just the
 * fault (D-18) -- proven here by asserting on message content, not merely on the exception
 * class, since named-constructor message content is a common surviving-mutant source.
 */
mutates(
    ApiException::class,
    AssociationTypeException::class,
    ConfigurationException::class,
    ObjectTypeException::class,
);

final class ExceptionHierarchyTest extends TestCase
{
    /**
     * Resolves the directory registered for the `ReyemTech\Hubspot\` PSR-4 prefix -- the same
     * dynamic-lookup technique `tests/Arch/SdkSurfaceTest.php` and `tests/Arch/SecretLoggingTest.php`
     * already use, rather than a hardcoded `__DIR__.'/../../../src'` -- so this test keeps
     * scanning the right tree under the firing harness's remapped autoloader too.
     */
    private static function registeredSrcRoot(): string
    {
        foreach (spl_autoload_functions() as $autoloadFunction) {
            if (is_array($autoloadFunction) && $autoloadFunction[0] instanceof ClassLoader) {
                $prefixes = $autoloadFunction[0]->getPrefixesPsr4();

                if (isset($prefixes['ReyemTech\\Hubspot\\'][0])) {
                    return rtrim($prefixes['ReyemTech\\Hubspot\\'][0], '/');
                }
            }
        }

        throw new RuntimeException('ReyemTech\\Hubspot\\ PSR-4 prefix is not registered.');
    }

    public function test_the_hierarchy_has_exactly_four_members_and_no_more_no_fewer(): void
    {
        $expected = [
            ApiException::class,
            AssociationTypeException::class,
            ConfigurationException::class,
            ObjectTypeException::class,
        ];
        sort($expected);

        $exceptionsDirectory = self::registeredSrcRoot().'/Exceptions';

        self::assertDirectoryExists($exceptionsDirectory);

        $actual = [];

        foreach (glob($exceptionsDirectory.'/*.php') ?: [] as $file) {
            $fqcn = 'ReyemTech\\Hubspot\\Exceptions\\'.basename($file, '.php');

            // Skips the interface itself (HubspotException.php) -- it is the hierarchy's root,
            // not a member of it, and interface_exists() (not class_exists()) is what tells the
            // two apart without accidentally treating an interface as implementing itself.
            if (! class_exists($fqcn)) {
                continue;
            }

            if (is_a($fqcn, HubspotException::class, true)) {
                $actual[] = $fqcn;
            }
        }

        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'Expected exactly the four locked hierarchy members under src/Exceptions/ -- a fifth '
            .'member (or a lost one) is a deliberate design change, not a silent addition.',
        );
    }

    public function test_each_member_is_individually_catchable_and_all_four_are_catchable_via_hubspot_exception(): void
    {
        $configurationException = ConfigurationException::missingToken();
        $objectTypeException = ObjectTypeException::unmappable('p_widgets');
        $associationTypeException = AssociationTypeException::directionNotResolvable('contacts', 'companies');
        $apiException = ApiException::connectionFailure(new RuntimeException('boom'));

        try {
            throw $configurationException;
        } catch (ConfigurationException $caught) {
            self::assertSame($configurationException, $caught);
        }

        try {
            throw $objectTypeException;
        } catch (ObjectTypeException $caught) {
            self::assertSame($objectTypeException, $caught);
        }

        try {
            throw $associationTypeException;
        } catch (AssociationTypeException $caught) {
            self::assertSame($associationTypeException, $caught);
        }

        try {
            throw $apiException;
        } catch (ApiException $caught) {
            self::assertSame($apiException, $caught);
        }

        foreach ([$configurationException, $objectTypeException, $associationTypeException, $apiException] as $exception) {
            try {
                throw $exception;
            } catch (HubspotException $caught) {
                self::assertSame($exception, $caught);
            }
        }
    }

    public function test_configuration_exception_missing_token_names_the_env_var_and_where_to_get_one(): void
    {
        $exception = ConfigurationException::missingToken();

        self::assertStringContainsString('HUBSPOT_TOKEN', $exception->getMessage());
        self::assertStringContainsString('Private App', $exception->getMessage());
    }

    public function test_configuration_exception_unknown_store_names_the_valid_store_values(): void
    {
        $exception = ConfigurationException::unknownStore('redis', ['cache', 'database']);

        self::assertStringContainsString('redis', $exception->getMessage());
        self::assertStringContainsString('cache', $exception->getMessage());
        self::assertStringContainsString('database', $exception->getMessage());
        self::assertStringContainsString('HUBSPOT_STORE', $exception->getMessage());
    }

    public function test_object_type_exception_unmappable_names_the_offending_type_and_the_fix(): void
    {
        $exception = ObjectTypeException::unmappable('p_widgetz');

        self::assertStringContainsString('p_widgetz', $exception->getMessage());
        self::assertStringContainsString('contacts', $exception->getMessage());
    }

    public function test_association_type_exception_direction_not_resolvable_states_the_failed_direction_only(): void
    {
        $exception = AssociationTypeException::directionNotResolvable('contacts', 'companies');

        self::assertStringContainsString('contacts -> companies', $exception->getMessage());
        // Names the inverse only to say it is never substituted -- proves the message does not
        // read as though 'companies -> contacts' were itself a usable fallback.
        self::assertStringContainsString('companies -> contacts', $exception->getMessage());
        self::assertStringContainsString('never substituted', $exception->getMessage());
    }

    public function test_association_type_exception_direction_not_resolvable_names_the_label_when_given(): void
    {
        $exception = AssociationTypeException::directionNotResolvable('contacts', 'companies', 'buyer');

        self::assertStringContainsString('contacts -> companies', $exception->getMessage());
        self::assertStringContainsString('buyer', $exception->getMessage());
    }

    public function test_a_canned_associations_v4_error_translates_to_the_package_api_exception(): void
    {
        // Proves the translator is not Objects-only (02-02-PLAN.md Task 1) -- an associations v4
        // namespace ApiException, built directly against the real SDK constructor (not the
        // package's own type, so no R1 concern in a test file), must translate exactly like the
        // Objects namespace does.
        $sdkException = new \HubSpot\Client\Crm\Associations\V4\ApiException(
            'association not found',
            404,
            [],
            'raw associations body',
        );

        $translator = new ExceptionTranslator;
        $result = $translator->translate($sdkException);

        self::assertInstanceOf(ApiException::class, $result);
        self::assertInstanceOf(HubspotException::class, $result);
        self::assertSame(404, $result->status());
        self::assertSame('raw associations body', $result->body());
        self::assertSame($sdkException, $result->getPrevious());
    }
}
