<?php

namespace App\Services;

use Aiobale\Model\BaleObject;
use BackedEnum;
use Illuminate\Validation\ValidationException;

class BaleMethodPayloadValidator
{
    /**
     * @param class-string<BaleObject> $class
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(string $class, array $payload): array
    {
        return $this->normalizeObject($class, $payload, 'payload');
    }

    /**
     * @param class-string<BaleObject> $class
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeObject(string $class, array $values, string $path): array
    {
        $schema = $class::schema();
        $normalized = [];
        $errors = [];

        foreach ($values as $name => $value) {
            if (!is_string($name) || !array_key_exists($name, $schema)) {
                $errors[] = sprintf('%s contains an unsupported field.', $path);
                continue;
            }

            $definition = $schema[$name];
            if ($value === null) {
                if (($definition['required'] ?? false) === true) {
                    $errors[] = sprintf('%s.%s cannot be null.', $path, $name);
                } else {
                    $normalized[$name] = null;
                }
                continue;
            }

            $type = $definition['type'] ?? 'mixed';
            if ($type === 'array') {
                if (!is_array($value) || !array_is_list($value)) {
                    $errors[] = sprintf('%s.%s must be a JSON array.', $path, $name);
                    continue;
                }
                $items = [];
                foreach ($value as $index => $item) {
                    $items[] = $this->normalizeValue(
                        $definition['items'] ?? 'mixed',
                        $item,
                        "{$path}.{$name}.{$index}",
                        $errors,
                    );
                }
                $normalized[$name] = $items;
                continue;
            }

            $normalized[$name] = $this->normalizeValue($type, $value, "{$path}.{$name}", $errors);
        }

        foreach ($schema as $name => $definition) {
            if (
                ($definition['required'] ?? false) === true
                && !array_key_exists($name, $values)
                && !array_key_exists('default', $definition)
            ) {
                $errors[] = sprintf('%s.%s is required.', $path, $name);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['payload' => $errors]);
        }

        return $normalized;
    }

    /**
     * @param list<string> $errors
     */
    private function normalizeValue(mixed $type, mixed $value, string $path, array &$errors): mixed
    {
        if ($type === 'mixed' || $type === null) {
            return $value;
        }
        if ($type === 'int') {
            if (!is_int($value)) {
                $errors[] = sprintf('%s must be an integer.', $path);
                return $value;
            }
            return $value;
        }
        if ($type === 'bool') {
            if (!is_bool($value) && !in_array($value, [0, 1], true)) {
                $errors[] = sprintf('%s must be a boolean.', $path);
                return $value;
            }
            return (bool) $value;
        }
        if ($type === 'string' || $type === 'bytes') {
            if (!is_string($value)) {
                $errors[] = sprintf('%s must be a string.', $path);
            }
            return $value;
        }
        if (is_string($type) && enum_exists($type)) {
            if (!is_string($value) && !is_int($value)) {
                $errors[] = sprintf('%s must be an enum value.', $path);
                return $value;
            }

            if (is_subclass_of($type, BackedEnum::class)) {
                $enum = $type::tryFrom($value);
                if ($enum !== null) {
                    return $enum;
                }
                if (is_string($value)) {
                    foreach ($type::cases() as $case) {
                        if ($case->name === $value) {
                            return $case;
                        }
                    }
                }
            }

            $errors[] = sprintf('%s is not a valid enum value.', $path);
            return $value;
        }
        if (
            is_string($type)
            && class_exists($type)
            && is_subclass_of($type, BaleObject::class)
        ) {
            if (!is_array($value) || array_is_list($value)) {
                $errors[] = sprintf('%s must be a JSON object.', $path);
                return $value;
            }

            return new $type($this->normalizeObject($type, $value, $path));
        }

        return $value;
    }
}
