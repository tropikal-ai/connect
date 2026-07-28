<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

use TropikalAI\Connect\Application\PublicChannels\RemoteResponse;

interface ControlPlaneGateway
{
    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, scalar|null>  $query
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        bool $bindOrigin = true,
        int $maxResponseBytes = 1_048_576,
    ): RemoteResponse;
}
