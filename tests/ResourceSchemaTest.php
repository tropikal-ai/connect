<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;
use TropikalAI\Connect\Domain\Capabilities\CapabilityDescriptor;
use TropikalAI\Connect\Domain\Capabilities\CapabilitySet;
use TropikalAI\Connect\Domain\Capabilities\FieldDescriptor;
use TropikalAI\Connect\Domain\Capabilities\OperationDescriptor;
use TropikalAI\Connect\Domain\Resources\ResourceSchema;
use TropikalAI\Connect\Domain\Security\SensitiveData;

final class ResourceSchemaTest extends TestCase
{
    public function test_empty_schema_exposes_nothing(): void
    {
        $this->assertSame([], (new ResourceSchema)->publicSchema([], []));
    }

    public function test_schema_projection_writes_and_action_grants_are_explicit(): void
    {
        $schema = new ResourceSchema([
            'posts' => [
                'label' => 'Posts',
                'identifier' => 'id',
                'fields' => [
                    'title' => ['type' => 'string', 'required' => true],
                    'body' => ['type' => 'text'],
                    'readonly' => ['type' => 'string', 'writable' => false],
                    'writeonly' => ['type' => 'string', 'readable' => false],
                ],
                'actions' => [
                    'publish' => ['label' => 'Publish'],
                ],
            ],
        ]);

        $public = $schema->publicSchema(['posts'], ['posts' => ['read', 'action:publish']]);
        $this->assertArrayHasKey('publish', $public['posts']['actions']);
        $this->assertArrayNotHasKey('writeonly', $public['posts']['fields']);
        $this->assertSame(['id' => 1, 'title' => 'A', 'body' => 'B', 'readonly' => 'R'], $schema->project('posts', [
            'id' => 1,
            'title' => 'A',
            'body' => 'B',
            'readonly' => 'R',
            'writeonly' => 'W',
            'secret_note' => 'hidden',
        ]));
        $this->assertSame(['id', 'unknown'], $schema->unknownWriteFields('posts', ['title' => 'A', 'id' => 1, 'unknown' => true]));
        $this->assertTrue($schema->allows(['posts' => ['action:publish']], 'posts', 'action:publish'));
        $this->assertFalse($schema->allows(['posts' => ['read']], 'posts', 'action:publish'));
    }

    public function test_secret_shaped_resource_fields_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResourceSchema([
            'posts' => [
                'fields' => [
                    'api_token' => ['type' => 'string'],
                ],
            ],
        ]);
    }

    public function test_public_payload_guard_rejects_secret_shaped_keys_recursively(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SensitiveData::assertPublicPayload(['nested' => ['refresh_token' => 'secret']]);
    }

    public function test_capability_descriptor_rejects_secret_shaped_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CapabilityDescriptor(
            sourceKind: 'connect_filament',
            resourceKey: 'posts',
            resourceLabel: 'Posts',
            fields: [
                'api_token' => new FieldDescriptor('api_token'),
            ],
        );
    }

    public function test_capability_payload_is_public_and_operation_based(): void
    {
        $set = new CapabilitySet([
            new CapabilityDescriptor(
                sourceKind: 'connect_filament',
                resourceKey: 'posts',
                resourceLabel: 'Posts',
                fields: [
                    'title' => new FieldDescriptor('title', writable: true, required: true),
                ],
                operations: [
                    new OperationDescriptor(
                        name: 'posts.list',
                        operation: 'list',
                        riskLevel: 'read',
                        outputSchema: ['type' => 'object'],
                    ),
                    new OperationDescriptor(
                        name: 'posts.create',
                        operation: 'create',
                        riskLevel: 'write',
                        inputSchema: ['type' => 'object'],
                        requiresConfirmation: true,
                    ),
                ],
                grants: ['read', 'write'],
            ),
        ]);

        $payload = $set->publicPayload();

        $this->assertSame('connect_filament', $payload[0]['source_kind']);
        $this->assertSame(['read', 'write'], $payload[0]['grants']);
        $this->assertSame('posts.create', $payload[0]['operations'][1]['name']);
        SensitiveData::assertPublicPayload($payload);
    }
}
