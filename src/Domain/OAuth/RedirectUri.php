<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\OAuth;

final readonly class RedirectUri
{
    public function __construct(public string $value)
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Redirect URI must be an absolute URL.');
        }
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->value, $candidate);
    }
}
