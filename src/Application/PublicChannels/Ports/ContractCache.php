<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels\Ports;

interface ContractCache
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $value */
    public function put(string $key, array $value, int $ttlSeconds): void;
}
