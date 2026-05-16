<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

final readonly class ClientRegistrationRequest
{
    public function __construct(
        private string $clientName,
        private array $redirectUris,
        private string $scope,
        private string $resource,
        private string $clientUri,
        private ?string $softwareId = null,
    ) {}

    public function toArray(): array
    {
        $payload = [
            'client_name' => $this->clientName,
            'redirect_uris' => array_values($this->redirectUris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => $this->scope,
            'resource' => $this->resource,
            'client_uri' => $this->clientUri,
            'application_type' => 'web',
        ];

        if ($this->softwareId !== null && $this->softwareId !== '') {
            $payload['software_id'] = $this->softwareId;
        }

        return $payload;
    }
}
