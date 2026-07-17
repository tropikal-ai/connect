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

    public function test_signed_request_rejects_missing_required_headers(): void
    {
        $headers = SignedRequest::headers('secret', 'install_123', 'POST', '/api/posts', '', '{"title":"A"}', 1000, 'nonce_1');

        foreach ([
            SignedRequest::INSTALLATION_HEADER,
            SignedRequest::TIMESTAMP_HEADER,
            SignedRequest::NONCE_HEADER,
            SignedRequest::BODY_HASH_HEADER,
            SignedRequest::SIGNATURE_HEADER,
        ] as $header) {
            $tampered = $headers;
            unset($tampered[$header]);

            try {
                (new SignedRequestVerifier(new InMemoryNonceStore, 300))->verify('secret', 'install_123', 'POST', '/api/posts', '', '{"title":"A"}', $tampered, 1000);
                $this->fail("Signed request without {$header} was accepted.");
            } catch (ConnectException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nonce_is_remembered_for_the_full_timestamp_window(): void
    {
        // The timestamp is accepted across ±tolerance, so a request stays valid
        // for 2×tolerance of wall-clock; the nonce must be remembered at least
        // that long or it becomes replayable in the tail of its window.
        $tolerance = 300;
        $store = new Support\RecordingNonceStore;
        $headers = SignedRequest::headers('secret', 'install_123', 'GET', '/api/posts', null, '', 1000, 'nonce_1');

        (new SignedRequestVerifier($store, $tolerance))->verify('secret', 'install_123', 'GET', '/api/posts', null, '', $headers, 1000);

        $this->assertSame([2 * $tolerance], $store->ttls);
    }
}
