<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Infrastructure\PublicChannels;

use TropikalAI\Connect\Application\PublicChannels\Ports\ControlPlaneGateway;
use TropikalAI\Connect\Application\PublicChannels\Ports\HttpTransport;
use TropikalAI\Connect\Application\PublicChannels\Ports\InstallationCredentialsProvider;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelException;
use TropikalAI\Connect\Application\PublicChannels\RemoteResponse;
use TropikalAI\Connect\Domain\Security\SignedRequest;

final readonly class SignedControlPlaneGateway implements ControlPlaneGateway
{
    public const CONNECT_MEDIA_TYPE = 'application/vnd.tropikal.connect.v1+json';

    public function __construct(
        private InstallationCredentialsProvider $credentials,
        private HttpTransport $transport,
        private int $timeoutSeconds = 30,
    ) {}

    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        bool $bindOrigin = true,
        int $maxResponseBytes = 1_048_576,
    ): RemoteResponse {
        $credentials = $this->credentials->current();
        if ($credentials === null) {
            throw new PublicChannelException('connect_not_connected', 503);
        }

        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        $body = $json === null ? '' : (string) json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = $bindOrigin
            ? SignedRequest::headersWithRequestOrigin(
                $credentials->signingSecret,
                $credentials->installationPublicId,
                $method,
                $path,
                $credentials->origin->value,
                $query,
                $body,
            )
            : SignedRequest::headers(
                $credentials->signingSecret,
                $credentials->installationPublicId,
                $method,
                $path,
                $query,
                $body,
            );
        $headers['Accept'] = self::CONNECT_MEDIA_TYPE;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if (str_starts_with($path, '/api/connect-filament/embed/')) {
            $headers['X-Embed-Origin'] = $credentials->origin->value;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = rtrim($credentials->controlPlaneUrl, '/').$path.($queryString !== '' ? '?'.$queryString : '');

        return $this->transport->send(
            $method,
            $url,
            $headers,
            $body,
            $this->timeoutSeconds,
            $maxResponseBytes,
        );
    }
}
