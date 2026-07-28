<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

interface PublicChannelLogger
{
    /** @param array<string, scalar|null> $context */
    public function warning(string $event, array $context = []): void;
}
