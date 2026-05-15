<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Resources;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class ResourceSchema
{
    public function __construct(private array $resources = [])
    {
        foreach ($resources as $slug => $resource) {
            SensitiveData::assertPublicKey((string) $slug);
            $identifier = $resource['identifier'] ?? 'id';
            SensitiveData::assertPublicKey((string) $identifier);
            foreach (array_keys($resource['fields'] ?? []) as $field) {
                SensitiveData::assertPublicKey((string) $field);
            }
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
                'fields' => $resource['fields'] ?? [],
                'permissions' => $resourcePermissions,
                'actions' => $actions,
            ];
        }

        return $schema;
    }

    public function project(string $slug, array $record): array
    {
        $resource = $this->resource($slug);
        $fields = array_values(array_unique([
            $this->identifier($resource),
            ...array_keys($resource['fields'] ?? []),
        ]));

        return array_intersect_key($record, array_flip($fields));
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

    private function permissionsFor(array $permissions, string $slug): array
    {
        $grants = $permissions[$slug] ?? [];

        return is_array($grants) ? array_values($grants) : [];
    }
}
