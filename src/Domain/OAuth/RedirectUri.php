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

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && ! ($scheme === 'http' && $isLoopback)) {
            throw new \InvalidArgumentException('Redirect URI must use https (http is allowed only for loopback).');
        }
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->value, $candidate);
    }
}
