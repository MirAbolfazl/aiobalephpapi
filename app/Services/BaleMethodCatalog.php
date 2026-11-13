<?php

namespace App\Services;

use Aiobale\Methods\MethodRegistry;
use Aiobale\Model\BaleMethod;
use Aiobale\Model\BaleObject;

class BaleMethodCatalog
{
    /** @return array<string, class-string<BaleMethod>> */
    public function methods(): array
    {
        $methods = [];
        foreach (MethodRegistry::all() as $name => $class) {
            if (
                is_subclass_of($class, BaleMethod::class)
                && !in_array($class, $this->authenticationMethods(), true)
            ) {
                $methods[$name] = $class;
            }
        }

        return $methods;
    }

    /** @return array{name: string, service: string, method: string, fields: array<string, mixed>, route: string} */
    public function describe(string $name, string $class): array
    {
        return [
            'name' => $name,
            'service' => $class::service(),
            'method' => $class::methodName(),
            'fields' => $this->describeSchema($class::schema()),
            'route' => $this->authenticationRoute($class),
        ];
    }

    /** @param class-string<BaleMethod> $class */
    private function authenticationRoute(string $class): string
    {
        return match ($class) {
            \Aiobale\Methods\Auth\StartPhoneAuth::class => 'POST /api/v1/bale/auth/start',
            \Aiobale\Methods\Auth\ValidateCode::class => 'POST /api/v1/bale/auth/challenges/{challenge}/verify-code',
            \Aiobale\Methods\Auth\ValidatePassword::class => 'POST /api/v1/bale/auth/challenges/{challenge}/verify-password',
            \Aiobale\Methods\Auth\SignUp::class => 'POST /api/v1/bale/auth/challenges/{challenge}/sign-up',
            \Aiobale\Methods\Auth\SignOut::class => 'POST /api/v1/bale/accounts/{account}/sign-out',
            default => 'POST /api/v1/bale/accounts/{account}/methods/' . $class::methodName(),
        };
    }

    /** @return list<class-string<BaleMethod>> */
    private function authenticationMethods(): array
    {
        return [
            \Aiobale\Methods\Auth\StartPhoneAuth::class,
            \Aiobale\Methods\Auth\ValidateCode::class,
            \Aiobale\Methods\Auth\ValidatePassword::class,
            \Aiobale\Methods\Auth\SignUp::class,
            \Aiobale\Methods\Auth\SignOut::class,
        ];
    }

    /** @param array<string, array<string, mixed>> $schema
     *  @return array<string, mixed>
     */
    private function describeSchema(array $schema, array $seen = [], int $depth = 0): array
    {
        $result = [];
        foreach ($schema as $name => $definition) {
            $type = $definition['type'] ?? 'mixed';
            $field = [
                'type' => is_string($type) ? $type : 'mixed',
                'required' => ($definition['required'] ?? false) === true,
            ];
            if (isset($definition['alias'])) {
                $field['wire_alias'] = (string) $definition['alias'];
            }
            if ($type === 'array') {
                $field['items'] = $this->typeName($definition['items'] ?? 'mixed');
            } elseif (is_string($type) && class_exists($type) && is_subclass_of($type, BaleObject::class)) {
                if (isset($seen[$type]) || $depth >= 4) {
                    $field['schema_ref'] = $type;
                } else {
                    $field['fields'] = $this->describeSchema(
                        $type::schema(),
                        $seen + [$type => true],
                        $depth + 1,
                    );
                }
            }
            $result[$name] = $field;
        }

        return $result;
    }

    private function typeName(mixed $type): string
    {
        return is_string($type) ? $type : 'mixed';
    }
}
