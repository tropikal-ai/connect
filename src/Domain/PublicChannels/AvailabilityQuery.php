<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final readonly class AvailabilityQuery
{
    private function __construct(
        public string $from,
        public string $to,
        public string $timezone,
        public int $slotMinutes,
        public int $durationMinutes,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromInput(
        array $query,
        string $businessTimezone = 'Europe/Berlin',
        int $slotMinutes = 30,
        int $durationMinutes = 15,
        int $maxAdvanceDays = 60,
    ): self {
        try {
            $timezone = new \DateTimeZone($businessTimezone);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('The booking timezone is invalid.', previous: $exception);
        }

        $today = new \DateTimeImmutable('today', $timezone);
        $from = self::date((string) ($query['from'] ?? ''), $timezone) ?? $today;
        if ($from < $today) {
            $from = $today;
        }
        $last = $from->modify('+'.max(1, $maxAdvanceDays).' days');
        $to = self::date((string) ($query['to'] ?? ''), $timezone) ?? $last;
        if ($to < $from) {
            throw new \InvalidArgumentException('The availability window is invalid.');
        }
        if ($to > $last) {
            $to = $last;
        }

        return new self(
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $timezone->getName(),
            max(1, $slotMinutes),
            max(1, $durationMinutes),
        );
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'timezone' => $this->timezone,
            'slot_minutes' => $this->slotMinutes,
            'duration_minutes' => $this->durationMinutes,
        ];
    }

    private static function date(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $date;
    }
}
