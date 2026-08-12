<?php

declare(strict_types=1);

namespace ReyemTech\Hubspot\Signals;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ReyemTech\Hubspot\Exceptions\ConfigurationException;
use ReyemTech\Hubspot\Registry\HubspotObjectType;

/**
 * `hubspot.signals.map`, validated as a whole (SIG-03, D-07) and consumed one signal at a time.
 * SHAPE mirrors `Webhooks\HandlerMap` (SHAPE ONLY -- never imported, per the layer boundary
 * `06-PATTERNS.md` states): a single-pass `validate()` that throws on the first bad entry in
 * declared array order.
 *
 * Read fresh from config on every call, exactly the way `BoundModelReader` and `Sync\ModelBindings`
 * both read `hubspot.models` -- bound as a singleton purely because it holds no transport
 * `Hubspot::fake()` would ever need to invalidate, never because its answer is cached.
 */
final class SignalMap
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly BoundModelReader $bindings,
    ) {}

    /**
     * Walks the whole configured map once, in declared array order, and throws on the FIRST bad
     * entry: a missing or malformed `object` key, a non-array `properties` key, an invalid
     * per-property merge-rule declaration ({@see MergeRule::fromDeclaration()}), or (D-03) an
     * `object` type no `hubspot.models` binding claims.
     *
     * A signal name declared twice in `hubspot.signals.map` is resolved by PHP's own array-key
     * semantics before this class is ever constructed -- `validate()` sees one entry per name and
     * there is no duplicate to detect (SIG-03 adjacency edge). Nothing here writes a detection
     * branch that can never fire.
     */
    public function validate(): void
    {
        foreach ($this->map() as $signalName => $entry) {
            $this->validateEntry((string) $signalName, $entry);
        }
    }

    public function knows(string $name): bool
    {
        return array_key_exists($name, $this->map());
    }

    /**
     * The normalised HubSpot object type a signal's subject belongs to.
     *
     * @throws ConfigurationException if no entry is named `$name`
     */
    public function objectTypeFor(string $name): string
    {
        $entry = $this->entryFor($name);

        return self::normalisedObjectType($name, $entry);
    }

    /**
     * @return array<string, MergeRule>
     *
     * @throws ConfigurationException if no entry is named `$name`
     */
    public function rulesFor(string $name): array
    {
        $entry = $this->entryFor($name);

        $properties = self::propertiesOf($name, $entry);

        $rules = [];

        foreach ($properties as $property => $declaration) {
            $rules[(string) $property] = MergeRule::fromDeclaration((string) $property, $declaration, $name);
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->map()));
    }

    private function validateEntry(string $signalName, mixed $entry): void
    {
        $normalisedObjectType = self::normalisedObjectType($signalName, $entry);
        $properties = self::propertiesOf($signalName, $entry);

        foreach ($properties as $property => $declaration) {
            MergeRule::fromDeclaration((string) $property, $declaration, $signalName);
        }

        if (! $this->bindings->claimsObjectType($normalisedObjectType)) {
            [$boundObjectType, $modelClass] = self::representativeBinding($this->bindings->all());

            throw ConfigurationException::signalObjectTypeMismatch(
                $signalName,
                $normalisedObjectType,
                $boundObjectType,
                $modelClass,
            );
        }
    }

    private function entryFor(string $name): mixed
    {
        $map = $this->map();

        if (! array_key_exists($name, $map)) {
            throw ConfigurationException::unknownSignalName($name, $this->names());
        }

        return $map[$name];
    }

    private static function normalisedObjectType(string $signalName, mixed $entry): string
    {
        if (! is_array($entry) || ! array_key_exists('object', $entry)) {
            throw ConfigurationException::invalidSignalMapEntry($signalName, 'the "object" key is missing');
        }

        return HubspotObjectType::normalise($entry['object'])->value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function propertiesOf(string $signalName, mixed $entry): array
    {
        if (! is_array($entry) || ! array_key_exists('properties', $entry) || ! is_array($entry['properties'])) {
            throw ConfigurationException::invalidSignalMapEntry(
                $signalName,
                'the "properties" key must be an array',
            );
        }

        return $entry['properties'];
    }

    /**
     * The bound model this D-03 check contrasts an unclaimed map object type against, for the
     * exception's directed message. `claimsObjectType()` itself answers only a boolean, so this
     * picks the FIRST configured binding as a representative example of what IS bound -- accurate
     * whether there is exactly one binding (the common case this plan's own tests exercise) or
     * several, since the message's job is to show the reader a real, working entry to model a fix
     * on, not to enumerate every binding.
     *
     * @param  array<class-string, BoundSignalSubject>  $bindings
     * @return array{0: string, 1: string}
     */
    private static function representativeBinding(array $bindings): array
    {
        if ($bindings === []) {
            return ['(none)', '(hubspot.models has no bindings at all)'];
        }

        $modelClass = array_key_first($bindings);

        return [$bindings[$modelClass]->objectType, $modelClass];
    }

    /**
     * @return array<string, mixed>
     */
    private function map(): array
    {
        /** @var array<string, mixed> $map */
        $map = $this->config->get('hubspot.signals.map', []);

        return $map;
    }
}
