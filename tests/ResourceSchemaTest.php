<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;
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
                ],
                'actions' => [
                    'publish' => ['label' => 'Publish'],
                ],
            ],
        ]);

        $public = $schema->publicSchema(['posts'], ['posts' => ['read', 'action:publish']]);
        $this->assertArrayHasKey('publish', $public['posts']['actions']);
        $this->assertSame(['id' => 1, 'title' => 'A', 'body' => 'B', 'readonly' => 'R'], $schema->project('posts', [
            'id' => 1,
            'title' => 'A',
            'body' => 'B',
            'readonly' => 'R',
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
}
