<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

final readonly class AuthorizationRequest
{
    public function __construct(
        private string $authorizationEndpoint,
        private string $clientId,
        private string $redirectUri,
        private string $scope,
        private string $resource,
        private string $state,
        private PkcePair $pkce,
    ) {}

    public function url(): string
    {
        return rtrim($this->authorizationEndpoint, '?').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'resource' => $this->resource,
            'state' => $this->state,
            'code_challenge' => $this->pkce->challenge,
            'code_challenge_method' => 'S256',
        ]);
    }
}
