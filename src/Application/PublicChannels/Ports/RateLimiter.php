<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

interface RateLimiter
{
    public function allow(string $bucket, string $source, int $limit, int $windowSeconds): bool;
}
