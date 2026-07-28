<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final readonly class RouteKey
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if ($value === '' || strlen($value) > 80 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) !== 1) {
            throw new \InvalidArgumentException('The Job route key is invalid.');
        }

        return new self($value);
    }
}
