<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Resources;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class ResourceSchema
{
    /** Grant prefix that narrows which readable fields a projection hands over. */
    public const FIELD_GRANT_PREFIX = 'field:';

    /**
     * Marks that a field selection was made at all.
     *
     * Without it, "the owner unticked everything" and "this installation
     * predates field selection" both look like zero `field:*` entries, and the
     * safe reading of one is the unsafe reading of the other. The marker makes
     * an empty selection say so, so it can fail closed.
     */
    public const FIELD_SELECTION_MARKER = 'fields:selected';

    public function __construct(private array $resources = [])
    {
        foreach ($resources as $slug => $resource) {
            SensitiveData::assertPublicKey((string) $slug);
            $identifier = $resource['identifier'] ?? 'id';
            SensitiveData::assertPublicKey((string) $identifier);
            foreach (array_keys($resource['fields'] ?? []) as $field) {
                SensitiveData::assertPublicKey((string) $field);
            }
            SensitiveData::assertPublicPayload($resource['fields'] ?? []);
            SensitiveData::assertPublicPayload($resource['actions'] ?? []);
        }
    }

    public function publicSchema(array $allowedResources, array $permissions): array
    {
        if ($allowedResources === []) {
            return [];
        }

        $schema = [];
        foreach ($this->resources as $slug => $resource) {
            if (! in_array($slug, $allowedResources, true)) {
                continue;
            }
            $resourcePermissions = $this->permissionsFor($permissions, (string) $slug);
            $actions = [];
            foreach ($resource['actions'] ?? [] as $name => $definition) {
                if (in_array("action:{$name}", $resourcePermissions, true)) {
                    $actions[$name] = [
                        'label' => $definition['label'] ?? $name,
                        'description' => $definition['description'] ?? '',
                    ];
                }
            }
            $schema[$slug] = [
                'label' => $resource['label'] ?? $slug,
                'identifier' => $this->identifier($resource),
                'fields' => $this->readableFieldDefinitions($resource),
                'permissions' => $resourcePermissions,
                'actions' => $actions,
            ];
        }

        return $schema;
    }

    /**
     * Narrow a record to what the host is allowed to hand over.
     *
     * Readable fields can be narrowed further per installation by `field:<name>`
     * grants in `$permissions[$slug]`, the same mechanism as `action:{$name}`.
     * The identifier always travels — nothing downstream could be identified
     * without it — and an unselected field is dropped HERE, at the source, so a
     * consumer never has to be trusted to filter on receipt.
     *
     * @param  array<string, array<int, string>>  $permissions
     */
    public function project(string $slug, array $record, array $permissions = []): array
    {
        $resource = $this->resource($slug);
        $readable = array_keys($this->readableFieldDefinitions($resource));

        $selected = $this->selectedFields($permissions, $slug);
        if ($selected !== null) {
            $readable = array_values(array_intersect($readable, $selected));
        }

        $fields = array_values(array_unique([
            $this->identifier($resource),
            ...$readable,
        ]));

        return array_intersect_key($record, array_flip($fields));
    }

    /**
     * The `field:<name>` selection declared for a resource, or null when none is.
     *
     * Null is not "select nothing" but "no selection was ever declared", which is
     * what every installation stored before field selection existed — those keep
     * receiving every readable field.
     *
     * @param  array<string, array<int, string>>  $permissions
     * @return array<int, string>|null
     */
    public function selectedFields(array $permissions, string $slug): ?array
    {
        $selected = [];
        $declared = false;

        foreach ($this->permissionsFor($permissions, $slug) as $grant) {
            if (! is_string($grant)) {
                continue;
            }
            if ($grant === self::FIELD_SELECTION_MARKER) {
                $declared = true;

                continue;
            }
            if (str_starts_with($grant, self::FIELD_GRANT_PREFIX)) {
                $declared = true;
                $selected[] = substr($grant, strlen(self::FIELD_GRANT_PREFIX));
            }
        }

        return $declared ? array_values(array_unique($selected)) : null;
    }

    public function unknownWriteFields(string $slug, array $payload): array
    {
        $resource = $this->resource($slug);
        $fields = array_filter(
            array_keys($resource['fields'] ?? []),
            fn (string $field): bool => ($resource['fields'][$field]['writable'] ?? true) !== false,
        );

        return array_values(array_diff(array_keys($payload), $fields));
    }

    public function allows(array $permissions, string $slug, string $grant): bool
    {
        return in_array($grant, $this->permissionsFor($permissions, $slug), true);
    }

    public function has(string $slug): bool
    {
        return isset($this->resources[$slug]);
    }

    private function resource(string $slug): array
    {
        if (! isset($this->resources[$slug])) {
            throw new \InvalidArgumentException("Resource is not declared: {$slug}");
        }

        return $this->resources[$slug];
    }

    private function identifier(array $resource): string
    {
        $identifier = $resource['identifier'] ?? 'id';

        return is_string($identifier) && $identifier !== '' ? $identifier : 'id';
    }

    private function readableFieldDefinitions(array $resource): array
    {
        return array_filter(
            $resource['fields'] ?? [],
            fn (mixed $definition): bool => is_array($definition) && ($definition['readable'] ?? true) !== false,
        );
    }

    private function permissionsFor(array $permissions, string $slug): array
    {
        $grants = $permissions[$slug] ?? [];

        return is_array($grants) ? array_values($grants) : [];
    }
}
