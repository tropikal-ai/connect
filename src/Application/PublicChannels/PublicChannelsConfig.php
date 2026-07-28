<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Application\PublicChannels;

final readonly class PublicChannelsConfig
{
    public function __construct(
        public string $availabilityRouteKey = 'intro-call-availability',
        public string $bookingRouteKey = 'intro-call-booking',
        public string $businessTimezone = 'Europe/Berlin',
        public int $slotMinutes = 30,
        public int $durationMinutes = 15,
        public int $maxAdvanceDays = 60,
        public int $contractCacheSeconds = 300,
        public int $assetMaxBytes = 1_048_576,
    ) {}
}
