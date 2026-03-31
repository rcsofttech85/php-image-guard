<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard;

use RcSoftTech\ImageGuard\Guard\GuardBuilder;
use RcSoftTech\ImageGuard\Report\PipelineResult;
use RcSoftTech\ImageGuard\Ssim\GdSsimDriver;
use RcSoftTech\ImageGuard\Ssim\ImagickSsimDriver;
use RcSoftTech\ImageGuard\Ssim\SsimCalculatorInterface;

use function assert;
use function extension_loaded;

/**
 * Primary entry point for the php-image-guard package.
 *
 * Three usage patterns:
 *
 *   //  Minimal shorthand
 *   $result = ImageGuard::check('original.jpg', $compressor);
 *
 *   //  Full fluent chain
 *   $result = ImageGuard::original('original.jpg')
 *       ->compressWith($compressor)
 *       ->threshold(0.92)
 *       ->startAt(75)
 *       ->maxQuality(95)
 *       ->step(5)
 *       ->onFail(OnFailBehavior::RECOMPRESS)
 *       ->run();
 *
 *   //  Batch
 *   $report = ImageGuard::batch(glob('uploads/*.jpg'))
 *       ->compressWith($compressor)
 *       ->threshold('balanced')
 *       ->run();
 *
 *   //  Standalone SSIM comparison
 *   $score = ImageGuard::compare('original.jpg', 'compressed.jpg');
 */
final class ImageGuard
{
    // Prevent instantiation — this is a pure static facade.
    private function __construct()
    {
    }

    // Static entry points

    /**
     * Minimal shorthand: compress and verify in one call.
     *
     * @param callable(int, string): string $compressor
     */
    public static function check(
        string $originalPath,
        callable $compressor,
        float $threshold = 0.92,
    ): PipelineResult {
        $result = self::original($originalPath)
            ->compressWith($compressor)
            ->threshold($threshold)
            ->run(self::resolveCalculator());

        assert($result instanceof PipelineResult);

        return $result;
    }

    /**
     * Begin a fluent chain for a single image.
     */
    public static function original(string $path): GuardBuilder
    {
        return GuardBuilder::forSingle($path);
    }

    /**
     * Begin a fluent chain for multiple images.
     *
     * @param list<string>|array<int|string, string> $paths
     */
    public static function batch(array $paths): GuardBuilder
    {
        return GuardBuilder::forBatch(array_values($paths));
    }

    /**
     * Standalone SSIM comparison — no retry loop, no compression.
     * Returns a float SSIM score in [0.0, 1.0].
     */
    public static function compare(string $pathA, string $pathB): float
    {
        return self::resolveCalculator()->compare($pathA, $pathB);
    }

    // Driver resolution

    /**
     * Resolve the best available SSIM driver.
     * Prefers Imagick when available; falls back to pure-PHP GD.
     */
    public static function resolveCalculator(): SsimCalculatorInterface
    {
        return extension_loaded('imagick')
            ? new ImagickSsimDriver()
            : new GdSsimDriver();
    }
}
