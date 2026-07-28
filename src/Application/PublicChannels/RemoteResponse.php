<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels;

final readonly class RemoteResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /** @return array<string, mixed> */
    public function json(): array
    {
        $value = json_decode($this->body, true);

        return is_array($value) ? $value : [];
    }

    public function contentType(): string
    {
        return $this->headers['content-type'] ?? 'application/octet-stream';
    }
}
