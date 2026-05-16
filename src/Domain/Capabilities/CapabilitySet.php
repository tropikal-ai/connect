<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Capabilities;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class CapabilitySet
{
    /** @param array<int, CapabilityDescriptor> $capabilities */
    public function __construct(private array $capabilities = []) {}

    public function publicPayload(): array
    {
        $payload = array_map(
            fn (CapabilityDescriptor $capability): array => $capability->toArray(),
            $this->capabilities,
        );
        SensitiveData::assertPublicPayload($payload);

        return $payload;
    }
}
