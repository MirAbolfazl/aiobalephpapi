<?php

namespace App\Services;

use Aiobale\Model\BaleObject;
use BackedEnum;
use DateTimeInterface;

class BaleResponseSerializer
{
    public function normalize(mixed $value): mixed
    {
        if ($value instanceof BaleObject) {
            return $this->normalize($value->toArray(false));
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
