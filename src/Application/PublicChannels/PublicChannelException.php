<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels;

final class PublicChannelException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 503,
        public readonly array $details = [],
        string $message = 'The public channel is unavailable.',
    ) {
        parent::__construct($message);
    }
}
