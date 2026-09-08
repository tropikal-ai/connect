<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\Connect\Domain\Security\SignedRequestContext;

final class SignedRequestContextTest extends TestCase
{
    public function test_context_is_bound_to_exact_request_without_changing_legacy_signature(): void
    {
        $base = SignedRequest::headersWithRequestOrigin('fixture-secret', 'installation', 'POST', '/chat', 'https://fixture.test', '', '{}', 1000, 'nonce');
        $signature = $base[SignedRequest::SIGNATURE_HEADER];
        $extension = SignedRequestContext::headers('fixture-secret', $signature, '{"v":1,"fixture":"value"}');
        $headers = [...$base, ...$extension];
        $this->assertSame($signature, $headers[SignedRequest::SIGNATURE_HEADER]);
        $this->assertSame('{"v":1,"fixture":"value"}', SignedRequestContext::verify('fixture-secret', $signature, $extension));
        $this->assertNull(SignedRequestContext::verify('fixture-secret', $signature, []));
    }

    public function test_present_invalid_or_partially_stripped_context_never_downgrades(): void
    {
        $signature = str_repeat('a', 64);
        $headers = SignedRequestContext::headers('fixture-secret', $signature, 'fixture');
        foreach ([
            ['wrong-secret', $signature, $headers],
            ['fixture-secret', str_repeat('b', 64), $headers],
            ['fixture-secret', $signature, [SignedRequestContext::PAYLOAD_HEADER => $headers[SignedRequestContext::PAYLOAD_HEADER]]],
            ['fixture-secret', $signature, [SignedRequestContext::PROOF_HEADER => $headers[SignedRequestContext::PROOF_HEADER]]],
            ['fixture-secret', $signature, [...$headers, SignedRequestContext::PAYLOAD_HEADER => 'dGFtcGVyZWQ=']],
        ] as $case) {
            try {
                SignedRequestContext::verify(...$case);
                $this->fail('Tampered context was accepted.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_context_is_size_bounded(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SignedRequestContext::headers('fixture-secret', str_repeat('a', 64), str_repeat('a', 4097));
    }

    public function test_version_one_cross_language_vector_and_http_header_case(): void
    {
        $headers = SignedRequestContext::headers('fixture-secret', str_repeat('a', 64), '{"v":1}');
        $this->assertSame('eyJ2IjoxfQ', $headers[SignedRequestContext::PAYLOAD_HEADER]);
        $this->assertSame('9d86dde167a763b2f2e4d3a115c8dcdbbf77713882d599409ed6112d5c3012b4', $headers[SignedRequestContext::PROOF_HEADER]);
        $this->assertSame('{"v":1}', SignedRequestContext::verify('fixture-secret', str_repeat('a', 64), array_change_key_case($headers, CASE_LOWER)));
    }
}
