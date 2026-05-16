<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\Ports;

interface NonceStore
{
    public function claim(string $installationId, string $nonce, int $ttlSeconds): bool;
}
