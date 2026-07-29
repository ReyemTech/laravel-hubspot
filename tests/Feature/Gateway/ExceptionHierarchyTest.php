<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Tests\Feature\Gateway;

use Composer\Autoload\ClassLoader;
use HubSpot\Client\Crm\Associations\V4\Model\Error;
use ReyemTech\Hubspot\Exceptions\ApiException;
use ReyemTech\Hubspot\Exceptions\AssociationTypeException;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Exceptions\HubspotException;
use ReyemTech\Hubspot\Exceptions\ObjectTypeException;
use ReyemTech\Hubspot\Gateway\AssociationCategory;
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

        // Exact text, not merely a substring -- the message is built from three concatenated
        // fragments, and a substring check alone would not notice the fragments being reordered
        // or dropped (a common surviving-mutant shape for concatenated named-constructor
        // messages, per this plan's own <verification> guidance).
        self::assertSame(
            'HUBSPOT_TOKEN is not set. Create a HubSpot Service Key (HubSpot account settings '
            .'-> Integrations -> Service Keys) and set HUBSPOT_TOKEN in your .env file before '
            .'making any Gateway call. A legacy Private App token also works.',
            $exception->getMessage(),
        );
    }

    /**
     * The error a user actually sees when their token is missing must not send them somewhere
     * different from `config/hubspot.php`. HubSpot classifies Private Apps as legacy, the config
     * file's own comment now says to create a Service Key, and a message pointing at a different
     * settings page than the documentation is worse than a vague one — it costs the reader a trip
     * to a deprecated screen before they discover the mismatch.
     */
    public function test_the_missing_token_message_names_the_same_credential_as_the_config_file(): void
    {
        $message = ConfigurationException::missingToken()->getMessage();
        $config = (string) file_get_contents(__DIR__.'/../../../config/hubspot.php');

        self::assertStringContainsString('Service Key', $message);
        self::assertStringContainsString('Service Key', $config);
        self::assertStringNotContainsString(
            'Create a HubSpot Private App',
            $message,
            'The missing-token message must not steer users to the legacy Private App flow.',
        );
    }

    public function test_configuration_exception_unknown_store_names_the_valid_store_values(): void
    {
        $exception = ConfigurationException::unknownStore('redis', ['cache', 'database']);

        self::assertSame(
            'HUBSPOT_STORE is set to "redis", which is not a supported store. Set it to one of: cache, database.',
            $exception->getMessage(),
        );
    }

    public function test_object_type_exception_unmappable_names_the_offending_type_and_the_fix(): void
    {
        $exception = ObjectTypeException::unmappable('p_widgetz');

        self::assertSame(
            'Object type "p_widgetz" has no known mapping. Confirm the spelling matches a '
            .'HubSpot object type (for example "contacts" or "deals") or your custom object\'s '
            .'fully-qualified type (for example "p12345_my_object"), then map the local model '
            .'that should sync to it before retrying.',
            $exception->getMessage(),
        );
    }

    /**
     * The four named constructors 03-01 added, pinned as whole literal strings.
     *
     * Whole strings rather than substrings, deliberately: `pest --mutate` generates a concatenation
     * mutant per `.` operator and a string mutant per literal, and every one of them survives a
     * `assertStringContainsString` — an earlier plan leaked 31 such survivors before this file
     * started asserting messages in full.
     */
    public function test_object_type_exception_non_string_object_type_explains_where_strict_types_binds(): void
    {
        self::assertSame(
            'A HubSpot object type was given as type int and cannot be normalised. Pass it as a '
            .'string — for example "deals", "line_items", or a custom object\'s fully-qualified type '
            .'"p12345_my_object". This is validated here rather than by the parameter type because '
            .'declare(strict_types=1) binds at the calling file, not at this package\'s: in a file '
            .'without it, 0 would have arrived as "0" and true as "1", and normalisation would have '
            .'reported an unknown object type nobody wrote.',
            ObjectTypeException::nonStringObjectType(0)->getMessage(),
        );
    }

    public function test_a_non_string_label_message_says_a_paired_label_is_named_per_direction(): void
    {
        self::assertSame(
            'An association label was given as type bool. Pass the label as a string, exactly as the '
            .'portal spells it -- a paired label carries a DIFFERENT name in each direction ("Deals" '
            .'one way, "People" the other), so a label is never derived and never coerced. Pass null '
            .'only for the unlabelled default type, which resolves through createDefault() and needs '
            .'no type id at all.',
            AssociationTypeException::nonStringLabel(true)->getMessage(),
        );
    }

    public function test_an_invalid_inverse_type_id_message_says_the_column_is_read_never_written(): void
    {
        self::assertSame(
            'An inverse association type id was given as type string, which is not a positive '
            .'integer. Record the id HubSpot issues for the OPPOSITE direction -- Contact -> Company '
            .'is 279 and Company -> Contact is 280 -- as an int, or null where no inverse has been '
            .'observed. HubSpot issues ids from 1 upward, so a zero or a negative is a value that was '
            .'defaulted rather than observed. Null is the safe answer of the two: the inverse id is '
            .'read for traversal and verification and is never written, so an absent one narrows a '
            .'diagnostic while a wrong one makes it report the wrong association as found.',
            AssociationTypeException::invalidInverseTypeId('280')->getMessage(),
        );
    }

    public function test_a_non_boolean_default_flag_message_names_the_string_false_trap(): void
    {
        self::assertSame(
            'An association type\'s default flag was given as type string. Pass true or false, or '
            .'null where no source states which type a bare association resolves to -- null is what '
            .'the seeded baseline carries, because that answer was measured against a real portal for '
            .'one object-type pair only. This is checked rather than coerced because a non-empty '
            .'string such as "false" is true in weak mode, which would turn a row that is not the '
            .'default into one that is.',
            AssociationTypeException::nonBooleanDefaultFlag('false')->getMessage(),
        );
    }

    public function test_association_type_exception_direction_not_resolvable_states_the_failed_direction_only(): void
    {
        $exception = AssociationTypeException::directionNotResolvable('contacts', 'companies');

        self::assertSame(
            'No association type is registered for the direction contacts -> companies. Register '
            .'this direction -- the inverse companies -> contacts is a different, unrelated '
            .'typeId and is never substituted automatically -- before associating these object '
            .'types.',
            $exception->getMessage(),
        );
    }

    public function test_association_type_exception_direction_not_resolvable_names_the_label_when_given(): void
    {
        $exception = AssociationTypeException::directionNotResolvable('contacts', 'companies', 'buyer');

        self::assertSame(
            'No association type is registered for the direction contacts -> companies labelled '
            .'"buyer". Register this direction -- the inverse companies -> contacts is a '
            .'different, unrelated typeId and is never substituted automatically -- before '
            .'associating these object types.',
            $exception->getMessage(),
        );
    }

    /**
     * The five members plan 02-05 added, asserted as **exact text** rather than by substring.
     *
     * That distinction is the whole reason these tests exist, and it is the same one this file's
     * header already records: every message here is built from a dozen concatenated fragments, and
     * `assertStringContainsString` cannot tell a correct message from one whose fragments have been
     * reordered or truncated. `pest --mutate` proves it — `ConcatSwitchSides` and `ConcatRemoveRight`
     * produced **31 surviving mutants** across these five constructors when the only assertions on
     * them were substring checks in `AssociationTypeTest` and `NeverTheInverseTest`. Those substring
     * assertions are still worth keeping where they are, because they assert the *contract* (the
     * message names the direction, the label, the fix) at the point of use; these assert the artefact.
     *
     * A failure here is usually benign — someone improved the wording — and the fix is to update the
     * expected string after reading the new one. A failure here alongside a green
     * `NeverTheInverseTest` is always benign. The reverse is not.
     */
    public function test_no_resolver_installed_names_the_direction_the_label_and_the_container_key(): void
    {
        $exception = AssociationTypeException::noResolverInstalled('notes', 'contacts', 'Attached note');

        self::assertSame(
            'No association type resolver is installed, so the direction notes -> contacts labelled '
            .'"Attached note" cannot be resolved to a typeId, and nothing was written. Bind '
            .'ReyemTech\Hubspot\Gateway\Contracts\AssociationTypeResolver in a service provider to an '
            .'implementation that resolves this exact direction -- the inverse contacts -> notes is a '
            .'different, unrelated typeId and is never substituted automatically -- or call associate(), '
            .'which uses the unlabelled default association type and resolves no typeId at all.',
            $exception->getMessage(),
        );
    }

    public function test_no_labels_given_steers_to_the_method_that_legitimately_resolves_nothing(): void
    {
        $exception = AssociationTypeException::noLabelsGiven();

        self::assertSame(
            'A labelled association write was requested with no labels, so there is no direction to '
            .'resolve and nothing was written. Pass at least one label, or call associate(), which uses '
            .'the unlabelled default association type and resolves no typeId at all.',
            $exception->getMessage(),
        );
    }

    public function test_unknown_association_category_lists_every_value_the_sdk_accepts(): void
    {
        $exception = AssociationTypeException::unknownAssociationCategory(
            'USER_DEFINEDD',
            AssociationCategory::values(),
        );

        self::assertSame(
            'Association category "USER_DEFINEDD" is not one the HubSpot API recognises. Use one of: '
            .'HUBSPOT_DEFINED, INTEGRATOR_DEFINED, USER_DEFINED, WORK.',
            $exception->getMessage(),
        );
    }

    public function test_a_non_string_category_message_explains_where_strict_types_actually_binds(): void
    {
        $exception = AssociationTypeException::nonStringAssociationCategory(3, AssociationCategory::values());

        self::assertSame(
            'An association category was given as type int. Pass one of the strings the HubSpot API '
            .'recognises -- HUBSPOT_DEFINED, INTEGRATOR_DEFINED, USER_DEFINED, WORK -- or a '
            .'ReyemTech\Hubspot\Gateway\AssociationCategory case, which makes the invalid value '
            .'unrepresentable. This is validated here rather than by the parameter type because '
            .'declare(strict_types=1) binds at the calling file, not at this package\'s: in a file '
            .'without it, 1 and true would both have arrived as "1" and been reported as an unknown '
            .'category nobody wrote.',
            $exception->getMessage(),
        );
    }

    /**
     * The message names two real HubSpot type ids that a coerced value lands on — 1 for
     * Contact -> Primary Company, 19 for Deal -> Line Item — because "pass an int" alone does not
     * convey why this is rejected rather than cast. A reader who does not know that `true` becomes a
     * valid-looking type id will reasonably think the strictness is pedantry.
     */
    public function test_a_non_integer_type_id_message_names_the_real_ids_a_coerced_value_lands_on(): void
    {
        $exception = AssociationTypeException::nonIntegerTypeId(true);

        self::assertSame(
            'A HubSpot association type id was given as type bool. Pass it as an int -- a value held as '
            .'a string is cast at the call site with "(int) $typeId", never coerced here. This is '
            .'validated in the value object rather than by the parameter type because '
            .'declare(strict_types=1) binds at the calling file, not at this package\'s: in a file '
            .'without it, true would have arrived as 1 -- a real type id, Contact -> Primary Company -- '
            .'and 19.9 as 19, another real one, Deal -> Line Item. Either writes an association nobody '
            .'meant, and HubSpot reports no error for it.',
            $exception->getMessage(),
        );
    }

    public function test_a_non_positive_type_id_message_says_where_hubspot_ids_start(): void
    {
        $exception = AssociationTypeException::nonPositiveTypeId(0);

        self::assertSame(
            'A HubSpot association type id of 0 is not a valid id, and nothing was written. HubSpot type '
            .'ids start at 1 -- Contact -> Primary Company is 1 and Company -> Primary Contact is 2 -- '
            .'so a zero or negative id is a value that was defaulted rather than resolved. Resolve the '
            .'direction and pass the id registered for it.',
            $exception->getMessage(),
        );
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

    public function test_a_canned_associations_v4_error_with_a_deserialised_response_object_preserves_its_correlation_id(): void
    {
        // Distinguishes this from the test above -- that one leaves getResponseObject() at its
        // default null (never calling setResponseObject()), so the Objects-shaped and
        // Associations\V4-shaped `instanceof` branches in ExceptionTranslator::translate() are
        // equally untested past the null case. This exercises the v4 Model\Error branch for
        // real, the same way tests/Feature/Gateway/ExceptionTranslationTest.php already does for
        // the Objects namespace.
        $sdkException = new \HubSpot\Client\Crm\Associations\V4\ApiException(
            'association not found',
            404,
            [],
            'raw associations body',
        );
        $sdkException->setResponseObject(new Error([
            'correlation_id' => 'corr-assoc-404',
        ]));

        $translator = new ExceptionTranslator;
        $result = $translator->translate($sdkException);

        self::assertSame('corr-assoc-404', $result->correlationId());
    }
}
