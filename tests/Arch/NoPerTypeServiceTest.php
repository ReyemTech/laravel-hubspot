<?php

declare(strict_types=1);

/**
 * Success criterion 1's NEGATIVE half. `tests/Feature/Gateway/ObjectGatewayTest.php` proves eight
 * object types run through one gateway; this file proves nobody quietly added a ninth code path
 * alongside it.
 *
 * The competing package (`tapp/laravel-hubspot`) hand-writes one service per object type — 601
 * lines for contacts, 405 for companies, and roughly 2,500 more to cover the five it lacks. The
 * SDK's generic objects API removes that cost entirely, but only for as long as nobody reaches for
 * the familiar shape. A class named `ContactGateway`, `DealsService` or `LineItemRepository` is
 * that reach, and it fails here.
 *
 * Deliberately NOT an entry in `tests/Arch/rules.json`, for the same reason
 * `tests/Arch/SdkSurfaceTest.php` is not: `tests/Arch/FiringHarnessTest.php` requires every
 * manifest rule to own a violation fixture under `tests/Arch/Fixtures/`, and a fixture for this
 * rule would be a file named for an object type living permanently in the repository — precisely
 * the thing the rule exists to keep out. The matcher's own non-vacuity is proved by the
 * self-test below instead, which runs the real matcher against synthetic names.
 */

use Composer\Autoload\ClassLoader;

/**
 * The HubSpot CRM object types this package must never grow a dedicated class for. Held here as an
 * explicit list rather than derived from anything: "which names are object types" is a fact about
 * HubSpot, not about this codebase, and inferring it would make the rule drift silently.
 *
 * @return list<string>
 */
function reyemtech_hubspot_per_type_object_type_names(): array
{
    return [
        'Contact', 'Company', 'Deal', 'Product', 'LineItem', 'Ticket', 'Quote',
        'Note', 'Call', 'Meeting', 'Task', 'Email', 'Communication', 'PostalMail',
    ];
}

/**
 * Suffixes that turn an object-type noun into a service-shaped class name. Stripped before
 * comparison so `DealSync` is caught as surely as a bare `Deal`, while an unrelated name that
 * merely contains a type noun as a substring (say, `CompanyWideSettings`) is not — substring
 * matching would produce false positives nobody could act on.
 *
 * @return list<string>
 */
function reyemtech_hubspot_per_type_service_suffixes(): array
{
    return [
        'Gateway', 'Service', 'Client', 'Api', 'Repository', 'Sync', 'Syncer', 'Manager',
        'Factory', 'Builder', 'Resource', 'Endpoint', 'Handler', 'Adapter', 'Mapper', 'Model',
    ];
}

/**
 * Returns the object-type noun this class name is named for, or null if it is not named for one.
 */
function reyemtech_hubspot_per_type_offending_noun(string $className): ?string
{
    $stem = $className;

    foreach (reyemtech_hubspot_per_type_service_suffixes() as $suffix) {
        if ($stem !== $suffix && str_ends_with($stem, $suffix)) {
            $stem = substr($stem, 0, -strlen($suffix));

            break;
        }
    }

    // `Deals` and `Deal` are the same reach for the same shape; HubSpot's own object type strings
    // are plural, so the plural form is if anything the likelier spelling.
    $stem = rtrim($stem, 's') === '' ? $stem : rtrim($stem, 's');

    foreach (reyemtech_hubspot_per_type_object_type_names() as $noun) {
        if (strcasecmp($stem, $noun) === 0) {
            return $noun;
        }
    }

    return null;
}

function reyemtech_hubspot_per_type_src_root(): string
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

/**
 * Every class, interface, trait and enum short name declared under `src/`, keyed by the file that
 * declares it. Token-based rather than filename-based: PSR-4 makes the two agree today, but a file
 * declaring a second class alongside the one it is named for is exactly how a per-type service
 * would sneak past a filename check.
 *
 * @return array<string, string> short name => declaring file path
 */
function reyemtech_hubspot_per_type_declared_classes(string $root): array
{
    $declared = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (reyemtech_hubspot_per_type_declarations_in($file->getPathname()) as $shortName) {
            $declared[$shortName] = $file->getPathname();
        }
    }

    return $declared;
}

/**
 * @return list<string>
 */
function reyemtech_hubspot_per_type_declarations_in(string $path): array
{
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents($path)),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true),
    ));

    $names = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            continue;
        }

        $next = $tokens[$index + 1] ?? null;

        if (is_array($next) && $next[0] === T_STRING) {
            $names[] = $next[1];
        }
    }

    return $names;
}

test('no class under src/ is named for a single HubSpot object type', function (): void {
    $declared = reyemtech_hubspot_per_type_declared_classes(reyemtech_hubspot_per_type_src_root());

    expect($declared)->not->toBeEmpty('Expected at least one class under src/, or this rule is vacuous.');

    $violations = [];

    foreach ($declared as $shortName => $path) {
        $noun = reyemtech_hubspot_per_type_offending_noun($shortName);

        if ($noun !== null) {
            $violations[] = "{$path} declares {$shortName}, which is named for the HubSpot object type '{$noun}'.";
        }
    }

    expect($violations)->toBeEmpty(
        implode("\n", $violations)."\n".
        'This package serves every object type through one generic gateway (success criterion 1) — '.
        'add the capability to Gateway\\ObjectGateway and pass the object type as a string instead.',
    );
});

test('the per-type matcher fires on the names it is meant to catch and spares the ones it is not', function (): void {
    foreach (['Contact', 'Deals', 'ContactGateway', 'DealsService', 'LineItemRepository', 'TicketSync', 'quoteClient'] as $offending) {
        expect(reyemtech_hubspot_per_type_offending_noun($offending))
            ->not->toBeNull("Expected {$offending} to be rejected as a per-type service name.");
    }

    foreach (['ObjectGateway', 'HubspotObject', 'HubspotObjectPage', 'BatchResult', 'SearchQuery', 'CompanyWideSettings', 'Service'] as $allowed) {
        expect(reyemtech_hubspot_per_type_offending_noun($allowed))
            ->toBeNull("Expected {$allowed} to be accepted — it is not named for a single object type.");
    }
});
