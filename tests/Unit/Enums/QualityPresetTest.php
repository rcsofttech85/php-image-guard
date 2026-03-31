<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Enums;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Enums\QualityPreset;

#[CoversClass(QualityPreset::class)]
final class QualityPresetTest extends TestCase
{
    #[TestWith([QualityPreset::STRICT, 0.97])]
    #[TestWith([QualityPreset::BALANCED, 0.92])]
    #[TestWith([QualityPreset::LOOSE, 0.85])]
    public function testPresetReturnsCorrectFloatValue(QualityPreset $preset, float $expected): void
    {
        $actual = $preset->toFloat();

        $this->assertSame($expected, $actual);
    }

    #[TestWith(['strict', QualityPreset::STRICT])]
    #[TestWith(['balanced', QualityPreset::BALANCED])]
    #[TestWith(['loose', QualityPreset::LOOSE])]
    #[TestWith(['STRICT', QualityPreset::STRICT])]
    #[TestWith(['BALANCED', QualityPreset::BALANCED])]
    #[TestWith(['Loose', QualityPreset::LOOSE])]
    public function testFromStringResolvesCaseInsensitivePreset(string $input, QualityPreset $expected): void
    {
        $actual = QualityPreset::fromString($input);

        $this->assertSame($expected, $actual);
    }

    public function testFromStringThrowsForUnknownPreset(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown quality preset');

        QualityPreset::fromString('ultra');
    }
}
