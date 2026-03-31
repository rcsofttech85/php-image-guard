<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Enums;

use InvalidArgumentException;

use function sprintf;

enum QualityPreset
{
    case STRICT;
    case BALANCED;
    case LOOSE;

    public function toFloat(): float
    {
        return match ($this) {
            self::STRICT => 0.97,
            self::BALANCED => 0.92,
            self::LOOSE => 0.85,
        };
    }

    public static function fromString(string $preset): self
    {
        return match (strtolower($preset)) {
            'strict' => self::STRICT,
            'balanced' => self::BALANCED,
            'loose' => self::LOOSE,
            default => throw new InvalidArgumentException(sprintf('Unknown quality preset "%s". Valid values: strict, balanced, loose.', $preset)),
        };
    }
}
