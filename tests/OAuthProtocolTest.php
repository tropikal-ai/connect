<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests;

use PHPUnit\Framework\TestCase;
use TropikalAI\Connect\Domain\OAuth\AuthorizationRequest;
use TropikalAI\Connect\Domain\OAuth\ClientRegistrationRequest;
use TropikalAI\Connect\Domain\OAuth\OAuthState;
use TropikalAI\Connect\Domain\OAuth\PkcePair;
use TropikalAI\Connect\Domain\OAuth\RedirectUri;
use TropikalAI\Connect\Domain\OAuth\TokenRequest;
use TropikalAI\Connect\Domain\OAuth\TokenSet;

final class OAuthProtocolTest extends TestCase
{
    public function test_pkce_uses_s256_verifier_and_challenge(): void
    {
        $pair = PkcePair::fromVerifier(str_repeat('a', 64));

        $this->assertSame('_-BU_nrgy23GXDr5th1SCfQ5hR20PQulmXM33xVGaOs', $pair->challenge);
        $this->assertSame(str_repeat('a', 64), $pair->verifier);
        $this->assertGreaterThanOrEqual(43, strlen(PkcePair::generate()->verifier));
    }

    public function test_oauth_state_hash_validates_state_and_expiry(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $state = OAuthState::generate(60, $now);

        $this->assertTrue(OAuthState::valid($state->plain, $state->hash, $state->expiresAt, $now));
        $this->assertFalse(OAuthState::valid('wrong', $state->hash, $state->expiresAt, $now));
        $this->assertFalse(OAuthState::valid($state->plain, $state->hash, $state->expiresAt, $now->modify('+61 seconds')));
    }

    public function test_redirect_uri_exact_match_validation(): void
    {
        $uri = new RedirectUri('https://cms.example.com/tropikal-connect/oauth/callback');

        $this->assertTrue($uri->matches('https://cms.example.com/tropikal-connect/oauth/callback'));
        $this->assertFalse($uri->matches('https://cms.example.com/tropikal-connect/oauth/callback/'));
        $this->assertFalse($uri->matches('https://cms.example.com/other/callback'));
    }

    public function test_client_registration_payload_is_safe_public_metadata(): void
    {
        $payload = (new ClientRegistrationRequest(
            'Example CMS',
            ['https://cms.example.com/tropikal-connect/oauth/callback'],
            'example:install',
            'https://control.example.com/resource',
            'https://cms.example.com',
            'example-connect',
        ))->toArray();

        $this->assertSame('none', $payload['token_endpoint_auth_method']);
        $this->assertSame(['authorization_code', 'refresh_token'], $payload['grant_types']);
        $this->assertArrayNotHasKey('client_secret', $payload);
    }

    public function test_authorization_and_token_request_payloads(): void
    {
        $pkce = PkcePair::fromVerifier(str_repeat('b', 64));
        $url = (new AuthorizationRequest(
            'https://auth.example.com/oauth/authorize',
            'client_123',
            'https://cms.example.com/tropikal-connect/oauth/callback',
            'example:install',
            'https://control.example.com/resource',
            'state_123',
            $pkce,
        ))->url();

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($pkce->challenge, $query['code_challenge']);

        $this->assertSame('authorization_code', TokenRequest::authorizationCode('client_123', 'https://cms.example.com/callback', 'code', 'verifier', 'resource')['grant_type']);
        $this->assertSame('refresh_token', TokenRequest::refreshToken('client_123', 'refresh', 'resource')['grant_type']);
    }

    public function test_token_response_validation_and_redaction(): void
    {
        $tokens = TokenSet::fromArray([
            'access_token' => 'access-server-only',
            'refresh_token' => 'refresh-server-only',
            'expires_in' => 300,
        ]);

        $this->assertSame('access-server-only', $tokens->accessToken);
        $this->assertSame('[redacted]', $tokens->redacted()['refresh_token']);

        $this->expectException(\InvalidArgumentException::class);
        TokenSet::fromArray(['access_token' => 'access']);
    }
}
