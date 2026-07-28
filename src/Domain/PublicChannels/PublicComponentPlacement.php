<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final readonly class PublicComponentPlacement
{
    public function __construct(
        public PublicComponentType $component,
        public bool $autoInject,
    ) {}
}
