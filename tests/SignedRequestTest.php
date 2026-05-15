<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;
use TropikalAI\Connect\Application\SignedRequestVerifier;
use TropikalAI\Connect\Domain\Security\CanonicalQuery;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\Connect\Exceptions\ConnectException;
use TropikalAI\Connect\Tests\Support\InMemoryNonceStore;

final class SignedRequestTest extends TestCase
{
    public function test_signed_request_includes_query_and_verifies(): void
    {
        $headers = SignedRequest::headers('secret', 'install_123', 'GET', '/api/posts', 'b=2&a=1', '', 1000, 'nonce_1');

        $this->assertSame('a=1&b=2', CanonicalQuery::normalize('b=2&a=1'));
        $this->assertArrayHasKey(SignedRequest::SIGNATURE_HEADER, $headers);

        $verifier = new SignedRequestVerifier(new InMemoryNonceStore, 300);
        $verifier->verify('secret', 'install_123', 'GET', '/api/posts', 'a=1&b=2', '', $headers, 1000);

        $this->expectException(ConnectException::class);
        $verifier->verify('secret', 'install_123', 'GET', '/api/posts', 'a=1&b=2', '', $headers, 1000);
    }

    public function test_signed_request_rejects_tampering(): void
    {
        $headers = SignedRequest::headers('secret', 'install_123', 'POST', '/api/posts', ['filter' => ['status' => 'draft']], '{"title":"A"}', 1000, 'nonce_1');

        $cases = [
            ['secret', 'other', 'POST', '/api/posts', ['filter' => ['status' => 'draft']], '{"title":"A"}', $headers, 1000],
            ['secret', 'install_123', 'GET', '/api/posts', ['filter' => ['status' => 'draft']], '{"title":"A"}', $headers, 1000],
            ['secret', 'install_123', 'POST', '/api/posts/1', ['filter' => ['status' => 'draft']], '{"title":"A"}', $headers, 1000],
            ['secret', 'install_123', 'POST', '/api/posts', ['filter' => ['status' => 'published']], '{"title":"A"}', $headers, 1000],
            ['secret', 'install_123', 'POST', '/api/posts', ['filter' => ['status' => 'draft']], '{"title":"B"}', $headers, 1000],
            ['secret', 'install_123', 'POST', '/api/posts', ['filter' => ['status' => 'draft']], '{"title":"A"}', $headers, 2000],
        ];

        foreach ($cases as $case) {
            try {
                (new SignedRequestVerifier(new InMemoryNonceStore, 300))->verify(...$case);
                $this->fail('Tampered signed request was accepted.');
            } catch (ConnectException) {
                $this->addToAssertionCount(1);
            }
        }

        unset($headers[SignedRequest::SIGNATURE_HEADER]);
        $this->expectException(ConnectException::class);
        (new SignedRequestVerifier(new InMemoryNonceStore, 300))->verify('secret', 'install_123', 'POST', '/api/posts', [], '', $headers, 1000);
    }
}
