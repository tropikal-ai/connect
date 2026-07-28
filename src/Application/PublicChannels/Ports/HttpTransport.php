<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

use TropikalAI\Connect\Application\PublicChannels\RemoteResponse;

interface HttpTransport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        string $body,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): RemoteResponse;
}
