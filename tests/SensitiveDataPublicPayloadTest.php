<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TropikalAI\Connect\Domain\Security\SensitiveData;

/**
 * The public-payload guard must relay a real embed-chat reply.
 *
 * Regression: `resume_token` matches the `token` KEY_MARKER, so the guard threw
 * on every bridged chat response. The control plane answered 200 with a valid
 * reply, the bridge rejected its own upstream, and the widget showed "The
 * assistant is not reachable right now" to visitors on every site.
 *
 * The allow-list is exact-match only, so these tests also pin that nothing
 * genuinely secret slipped through with it.
 */
final class SensitiveDataPublicPayloadTest extends TestCase
{
    public function test_a_real_embed_chat_response_is_relayed(): void
    {
        SensitiveData::assertPublicPayload([
            'status' => 'completed',
            'reply' => 'Hello! How can I assist you today?',
            'suggestions' => ['What does a visit cost?', 'Show me available times'],
            'session_id' => 'embed_1a2b3c4d5e6f7g8h',
            'channel_id' => 'wch_abc123',
            'workflow_run_id' => 'ffac4245-290d-4474-99ec-d7ab579a5fe7',
            // Deliberately not JWT-shaped: a realistic-looking token trips secret
            // scanners in CI. The guard only ever inspects the KEY name.
            'resume_token' => 'fake-resume-capability-for-tests',
            'correlation_id' => 'req_0001',
        ]);

        $this->assertTrue(true);
    }

    public function test_the_session_restore_response_is_relayed(): void
    {
        SensitiveData::assertPublicPayload([
            'session_id' => 'embed_1a2b3c4d5e6f7g8h',
            'messages' => [
                ['role' => 'user', 'text' => 'hi', 'created_at' => '2026-07-26T00:00:00Z'],
                ['role' => 'assistant', 'text' => 'hello', 'suggestions' => ['a']],
            ],
            'resume_token' => 'fake-rotated-value-for-tests',
        ]);

        $this->assertTrue(true);
    }

    public function test_resume_token_is_public_but_only_as_an_exact_match(): void
    {
        $this->assertFalse(SensitiveData::isSensitiveKey('resume_token'));
        $this->assertFalse(SensitiveData::isSensitiveKey('RESUME_TOKEN'));

        // Anything merely resembling it stays sensitive.
        $this->assertTrue(SensitiveData::isSensitiveKey('resume_token_secret'));
        $this->assertTrue(SensitiveData::isSensitiveKey('owner_resume_token'));
        $this->assertTrue(SensitiveData::isSensitiveKey('resume_tokens'));
    }

    #[DataProvider('secretKeys')]
    public function test_secrets_are_still_rejected(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        SensitiveData::assertPublicPayload([$key => 'value']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretKeys(): array
    {
        return [
            'access_token' => ['access_token'],
            'refresh_token' => ['refresh_token'],
            'client_secret' => ['client_secret'],
            'api_key' => ['api_key'],
            'assertion' => ['assertion_secret'],
            'hmac' => ['hmac'],
            'signature' => ['signature'],
            'private' => ['private_key'],
            'password' => ['password'],
            'credential' => ['credential'],
            'bare key' => ['key'],
            'suffix key' => ['signing_key'],
        ];
    }

    public function test_a_nested_secret_is_still_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SensitiveData::assertPublicPayload([
            'reply' => 'hi',
            'meta' => ['nested' => ['refresh_token' => 'secret']],
        ]);
    }

    public function test_a_bearer_value_marker_is_still_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SensitiveData::assertPublicPayload(['reply' => 'Bearer abc.def.ghi']);
    }

    public function test_resource_key_precedent_still_holds(): void
    {
        $this->assertFalse(SensitiveData::isSensitiveKey('resource_key'));
        $this->assertTrue(SensitiveData::isSensitiveKey('other_key'));
    }

    public function test_redaction_leaves_resume_token_readable(): void
    {
        $redacted = SensitiveData::redact([
            'resume_token' => 'visible',
            'access_token' => 'hidden',
        ]);

        $this->assertSame('visible', $redacted['resume_token']);
        $this->assertSame('[redacted]', $redacted['access_token']);
    }
}
