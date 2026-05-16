<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\Capabilities;

use TropikalAI\Connect\Domain\Security\SensitiveData;

final readonly class OperationDescriptor
{
    public function __construct(
        public string $name,
        public string $operation,
        public string $riskLevel,
        public array $inputSchema = [],
        public array $outputSchema = [],
        public bool $requiresConfirmation = false,
    ) {
        SensitiveData::assertPublicKey($this->name);
        SensitiveData::assertPublicPayload($this->inputSchema);
        SensitiveData::assertPublicPayload($this->outputSchema);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'operation' => $this->operation,
            'risk_level' => $this->riskLevel,
            'requires_confirmation' => $this->requiresConfirmation,
            'input_schema' => $this->inputSchema,
            'output_schema' => $this->outputSchema,
        ];
    }
}
