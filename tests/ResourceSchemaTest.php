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

    public function test_field_grants_narrow_the_projection_but_never_drop_the_identifier(): void
    {
        $schema = $this->postsSchema();
        $permissions = ['posts' => ['read', 'field:title']];

        $this->assertSame(['id' => 1, 'title' => 'A'], $schema->project('posts', [
            'id' => 1,
            'title' => 'A',
            'body' => 'B',
            'readonly' => 'R',
        ], $permissions));
    }

    /**
     * An empty selection must still identify the record it describes.
     */
    public function test_identifier_travels_even_when_no_field_is_selected(): void
    {
        $schema = $this->postsSchema();

        $this->assertSame(['id' => 1], $schema->project('posts', [
            'id' => 1,
            'title' => 'A',
            'body' => 'B',
        ], ['posts' => ['read', 'field:nothing_matches_this']]));
    }

    /**
     * The dangerous ambiguity: "the owner unticked everything" and "this
     * installation predates field selection" would otherwise both read as zero
     * `field:*` entries — and the safe reading of one is the unsafe reading of
     * the other. Unticking everything must fail closed, not fall back to all.
     */
    public function test_unticking_every_field_hands_over_only_the_identifier(): void
    {
        $schema = $this->postsSchema();
        $record = ['id' => 1, 'title' => 'A', 'body' => 'B'];

        $this->assertSame(['id' => 1], $schema->project('posts', $record, [
            'posts' => ['read', 'fields:selected'],
        ]));
        $this->assertSame([], $schema->selectedFields(['posts' => ['read', 'fields:selected']], 'posts'));

        // Without the marker the same permissions mean "never chose", which is
        // the pre-existing installation, and every readable field still travels.
        $this->assertSame(
            ['id' => 1, 'title' => 'A', 'body' => 'B'],
            $schema->project('posts', $record, ['posts' => ['read']]),
        );
    }

    /**
     * Every installation stored before field selection existed has no `field:*`
     * entry at all. That must keep meaning "all readable fields", not "none".
     */
    public function test_absent_field_grants_project_every_readable_field(): void
    {
        $schema = $this->postsSchema();
        $record = ['id' => 1, 'title' => 'A', 'body' => 'B', 'readonly' => 'R', 'writeonly' => 'W'];

        $this->assertSame(
            $schema->project('posts', $record),
            $schema->project('posts', $record, ['posts' => ['read', 'create']]),
        );
        $this->assertSame(['id' => 1, 'title' => 'A', 'body' => 'B', 'readonly' => 'R'], $schema->project('posts', $record));
    }

    /**
     * A `field:` grant can only ever narrow: naming an unreadable field does not
     * make it readable, and naming an undeclared one adds nothing.
     */
    public function test_field_grants_cannot_widen_beyond_readable_fields(): void
    {
        $schema = $this->postsSchema();

        $this->assertSame(['id' => 1, 'title' => 'A'], $schema->project('posts', [
            'id' => 1,
            'title' => 'A',
            'writeonly' => 'W',
            'secret_note' => 'hidden',
        ], ['posts' => ['read', 'field:title', 'field:writeonly', 'field:not_a_column']]));
    }

    public function test_field_grants_of_one_resource_do_not_narrow_another(): void
    {
        $schema = $this->postsSchema();

        $this->assertSame(['id' => 1, 'title' => 'A', 'body' => 'B', 'readonly' => 'R'], $schema->project('posts', [
            'id' => 1,
            'title' => 'A',
            'body' => 'B',
            'readonly' => 'R',
        ], ['pages' => ['field:title']]));
    }

    public function test_selected_fields_reports_null_when_nothing_is_declared(): void
    {
        $schema = $this->postsSchema();

        $this->assertNull($schema->selectedFields(['posts' => ['read']], 'posts'));
        $this->assertNull($schema->selectedFields([], 'posts'));
        $this->assertSame(['title', 'body'], $schema->selectedFields(
            ['posts' => ['read', 'field:title', 'field:body', 'field:title']],
            'posts',
        ));
    }

    private function postsSchema(): ResourceSchema
    {
        return new ResourceSchema([
            'posts' => [
                'label' => 'Posts',
                'identifier' => 'id',
                'fields' => [
                    'title' => ['type' => 'string', 'required' => true],
                    'body' => ['type' => 'text'],
                    'readonly' => ['type' => 'string', 'writable' => false],
                    'writeonly' => ['type' => 'string', 'readable' => false],
                ],
            ],
        ]);
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
