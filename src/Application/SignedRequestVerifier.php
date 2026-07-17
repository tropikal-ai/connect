<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application;

use TropikalAI\Connect\Application\Ports\NonceStore;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\Connect\Exceptions\ConnectException;

final readonly class SignedRequestVerifier
{
    public function __construct(
        private NonceStore $nonces,
        private int $toleranceSeconds = 300,
    ) {}

    public function verify(
        string $secret,
        string $expectedInstallationId,
        string $method,
        string $path,
        array|string|null $query,
        string $body,
        array $headers,
        ?int $now = null,
    ): void {
        $now ??= time();
        $installationId = $this->header($headers, SignedRequest::INSTALLATION_HEADER);
        $timestamp = (int) $this->header($headers, SignedRequest::TIMESTAMP_HEADER);
        $nonce = $this->header($headers, SignedRequest::NONCE_HEADER);
        $bodyHash = $this->header($headers, SignedRequest::BODY_HASH_HEADER);
        $signature = $this->header($headers, SignedRequest::SIGNATURE_HEADER);

        if (! hash_equals($expectedInstallationId, $installationId)) {
            throw new ConnectException('Signed request installation mismatch.');
        }
        if ($timestamp <= 0 || abs($now - $timestamp) > $this->toleranceSeconds) {
            throw new ConnectException('Signed request timestamp is outside tolerance.');
        }
        if (! hash_equals(SignedRequest::bodyHash($body), $bodyHash)) {
            throw new ConnectException('Signed request body hash mismatch.');
        }

        $expected = SignedRequest::sign($secret, $installationId, $method, $path, $query, $timestamp, $nonce, $bodyHash);
        if (! hash_equals($expected, $signature)) {
            throw new ConnectException('Signed request signature mismatch.');
        }
        // A timestamp is accepted across the whole ±tolerance window, so a given
        // request stays valid for up to 2×tolerance of wall-clock. The nonce
        // must be remembered for at least that long, otherwise a captured
        // request becomes replayable once its nonce expires but its timestamp is
        // still in range.
        if (! $this->nonces->claim($installationId, $nonce, 2 * $this->toleranceSeconds)) {
            throw new ConnectException('Signed request nonce has already been used.');
        }
    }

    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                $header = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                $header = trim($header);
                if ($header !== '') {
                    return $header;
                }
            }
        }

        throw new ConnectException("Signed request header missing: {$name}");
    }
}
