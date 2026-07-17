<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Tests\Support;

use TropikalAI\Connect\Application\Ports\NonceStore;

/** Records the TTL each nonce is claimed with, for replay-window assertions. */
final class RecordingNonceStore implements NonceStore
{
    /** @var array<int, int> */
    public array $ttls = [];

    private array $claims = [];

    public function claim(string $installationId, string $nonce, int $ttlSeconds): bool
    {
        $this->ttls[] = $ttlSeconds;
        $key = "{$installationId}:{$nonce}";
        if (isset($this->claims[$key])) {
            return false;
        }
        $this->claims[$key] = true;

        return true;
    }
}
