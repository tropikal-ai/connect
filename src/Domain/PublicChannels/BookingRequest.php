<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

final readonly class BookingRequest
{
    private function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $note,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
        public string $timezone,
        public string $bookingUuid,
        public string $turnstileToken,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input, ?\DateTimeImmutable $now = null): self
    {
        $name = self::bounded($input['name'] ?? '', 'Name', 1, 200);
        $email = self::bounded($input['email'] ?? '', 'Email', 3, 320);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid email is required.');
        }
        $phone = self::bounded($input['phone'] ?? '', 'Phone', 3, 60);
        $note = self::bounded($input['note'] ?? '', 'Note', 0, 2000);
        $bookingUuid = self::bounded($input['booking_uuid'] ?? '', 'Booking id', 8, 120);
        if (preg_match('/^[A-Za-z0-9._:-]+$/', $bookingUuid) !== 1) {
            throw new \InvalidArgumentException('The booking id is invalid.');
        }
        $turnstile = self::bounded($input['cf_turnstile_token'] ?? '', 'Human verification', 0, 4096);

        $timezoneName = self::bounded($input['timezone'] ?? '', 'Timezone', 1, 80);
        try {
            $timezone = new \DateTimeZone($timezoneName);
            $start = new \DateTimeImmutable(self::bounded($input['slot_start'] ?? '', 'Start time', 1, 100));
            $end = new \DateTimeImmutable(self::bounded($input['slot_end'] ?? '', 'End time', 1, 100));
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('The selected booking time is invalid.', previous: $exception);
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('The selected booking interval is invalid.');
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($start <= $now) {
            throw new \InvalidArgumentException('The selected booking time is in the past.');
        }

        return new self($name, $email, $phone, $note, $start, $end, $timezone->getName(), $bookingUuid, $turnstile);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'booking_uuid' => $this->bookingUuid,
            'summary' => 'TROPIKAL intro call — '.$this->name,
            'start' => $this->start->format(\DateTimeInterface::ATOM),
            'end' => $this->end->format(\DateTimeInterface::ATOM),
            'attendee_email' => $this->email,
            'attendee_name' => $this->name,
            'attendee_phone' => $this->phone,
            'description' => $this->note,
            'timezone' => $this->timezone,
        ];
    }

    private static function bounded(mixed $value, string $label, int $minimum, int $maximum): string
    {
        $value = trim((string) $value);
        $length = mb_strlen($value);
        if ($length < $minimum || $length > $maximum) {
            throw new \InvalidArgumentException("{$label} has an invalid length.");
        }

        return $value;
    }
}
