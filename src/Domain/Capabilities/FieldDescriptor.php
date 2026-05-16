<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Capabilities;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class FieldDescriptor
{
    public function __construct(
        public string $name,
        public string $type = 'string',
        public bool $readable = true,
        public bool $writable = false,
        public bool $required = false,
    ) {
        SensitiveData::assertPublicKey($this->name);
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'readable' => $this->readable,
            'writable' => $this->writable,
            'required' => $this->required,
        ];
    }
}
