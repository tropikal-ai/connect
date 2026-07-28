<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Infrastructure\PublicChannels;

use TropikalAI\Connect\Application\PublicChannels\Ports\ControlPlaneGateway;
use TropikalAI\Connect\Application\PublicChannels\Ports\HumanVerification;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelException;

/** Delegates Turnstile verification to the signed control plane boundary. */
final readonly class ControlPlaneHumanVerification implements HumanVerification
{
    private const PATH = '/api/connect-filament/public/human-verification';

    public function __construct(private ControlPlaneGateway $gateway) {}

    public function verify(string $token, string $remoteIp): bool
    {
        $response = $this->gateway->request('POST', self::PATH, [
            'token' => $token,
            'remote_ip' => $remoteIp,
        ]);
        $payload = $response->json();
        if ($response->status === 400 || $response->status === 422) {
            return false;
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new PublicChannelException('human_verification_unavailable', 503);
        }
        if (($payload['_tropikal_connect'] ?? null) === true && is_array($payload['data'] ?? null)) {
            $payload = $payload['data'];
        }

        return ($payload['verified'] ?? false) === true;
    }
}
