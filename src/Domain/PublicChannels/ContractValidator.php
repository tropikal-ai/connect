<?php

declare(strict_types=1);

namespace TropikalAI\Connect\Domain\PublicChannels;

/**
 * Validates the deterministic flat JSON-Schema subset served by Job routes.
 * The control plane remains authoritative; this preflight catches connector
 * drift before it creates a failed public run.
 */
final class ContractValidator
{
    /**
     * @param  array<string, mixed>  $contract
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function violations(array $contract, array $payload): array
    {
        $violations = [];
        $properties = is_array($contract['properties'] ?? null) ? $contract['properties'] : [];
        $required = is_array($contract['required'] ?? null) ? $contract['required'] : [];

        foreach ($required as $name) {
            $key = (string) $name;
            if (! array_key_exists($key, $payload)) {
                $violations[] = "missing required field '{$key}'";
            }
        }

        if (($contract['additionalProperties'] ?? true) === false) {
            foreach (array_keys($payload) as $key) {
                if (! array_key_exists($key, $properties)) {
                    $violations[] = "undeclared field '{$key}'";
                }
            }
        }

        foreach ($payload as $key => $value) {
            $schema = $properties[$key] ?? null;
            if (! is_array($schema)) {
                continue;
            }
            array_push($violations, ...self::valueViolations((string) $key, $value, $schema));
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function valueViolations(string $key, mixed $value, array $schema): array
    {
        $violations = [];
        $type = (string) ($schema['type'] ?? '');
        $validType = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && ! array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            '', 'null' => true,
            default => false,
        };
        if (! $validType) {
            return ["field '{$key}' has the wrong type"];
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
                $violations[] = "field '{$key}' is too short";
            }
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
                $violations[] = "field '{$key}' is too long";
            }
            if (($schema['format'] ?? null) === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $violations[] = "field '{$key}' must be an email";
            }
            if (($schema['format'] ?? null) === 'uri' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $violations[] = "field '{$key}' must be a URI";
            }
            if (isset($schema['pattern']) && @preg_match('~'.$schema['pattern'].'~u', $value) !== 1) {
                $violations[] = "field '{$key}' has an invalid format";
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < (float) $schema['minimum']) {
                $violations[] = "field '{$key}' is below the minimum";
            }
            if (isset($schema['maximum']) && $value > (float) $schema['maximum']) {
                $violations[] = "field '{$key}' is above the maximum";
            }
        }

        return $violations;
    }
}
