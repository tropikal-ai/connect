<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Capabilities;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class CapabilityDescriptor
{
    /**
     * @param  array<string, FieldDescriptor>  $fields
     * @param  array<int, OperationDescriptor>  $operations
     * @param  array<int, string>  $grants
     */
    public function __construct(
        public string $sourceKind,
        public string $resourceKey,
        public string $resourceLabel,
        public string $identifier = 'id',
        public array $fields = [],
        public array $operations = [],
        public array $grants = [],
        public string $status = 'active',
        public ?string $lastSyncedAt = null,
        public array $metadata = [],
    ) {
        SensitiveData::assertPublicKey($this->resourceKey);
        SensitiveData::assertPublicKey($this->identifier);
        SensitiveData::assertPublicPayload($this->metadata);
    }

    public function toArray(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field->toArray();
        }

        return [
            'source_kind' => $this->sourceKind,
            'resource_key' => $this->resourceKey,
            'resource_label' => $this->resourceLabel,
            'identifier' => $this->identifier,
            'fields' => $fields,
            'operations' => array_map(
                fn (OperationDescriptor $operation): array => $operation->toArray(),
                $this->operations,
            ),
            'grants' => array_values($this->grants),
            'status' => $this->status,
            'last_synced_at' => $this->lastSyncedAt,
            'metadata' => $this->metadata,
        ];
    }
}
