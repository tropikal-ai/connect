<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests\Support;

use TropikalAI\Connect\Application\Ports\NonceStore;

final class InMemoryNonceStore implements NonceStore
{
    private array $claims = [];

    public function claim(string $installationId, string $nonce, int $ttlSeconds): bool
    {
        $key = "{$installationId}:{$nonce}";
        if (isset($this->claims[$key])) {
            return false;
        }

        $this->claims[$key] = true;

        return true;
    }
}
