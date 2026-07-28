<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final readonly class Origin
{
    private function __construct(public string $value) {}

    public static function fromUrl(string $url): self
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('A valid absolute site URL is required.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(trim(rtrim((string) $parts['host'], '.'), '[]'));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('A valid HTTP site URL is required.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new \InvalidArgumentException('A valid HTTP site URL is required.');
        }
        if ($scheme !== 'https' && ! self::isLocalHost($host)) {
            throw new \InvalidArgumentException('Public Connect origins must use HTTPS.');
        }

        $portNumber = isset($parts['port']) ? (int) $parts['port'] : null;
        $port = $portNumber !== null
            && ! (($scheme === 'https' && $portNumber === 443) || ($scheme === 'http' && $portNumber === 80))
            ? ':'.$portNumber
            : '';
        $renderedHost = str_contains($host, ':') ? '['.$host.']' : $host;

        return new self($scheme.'://'.$renderedHost.$port);
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }
}
