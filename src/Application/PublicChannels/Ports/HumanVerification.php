<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

interface HumanVerification
{
    public function verify(string $token, string $remoteIp): bool;
}
