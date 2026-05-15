<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

final readonly class TokenRequest
{
    public static function authorizationCode(string $clientId, string $redirectUri, string $code, string $verifier, string $resource): array
    {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
            'resource' => $resource,
        ];
    }

    public static function refreshToken(string $clientId, string $refreshToken, string $resource): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            'resource' => $resource,
        ];
    }
}
