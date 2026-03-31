<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Guard;

use InvalidArgumentException;
use RcSoftTech\ImageGuard\Enums\OnFailBehavior;
use RcSoftTech\ImageGuard\Exceptions\ImageQualityException;
use RcSoftTech\ImageGuard\Report\PipelineResult;
use RcSoftTech\ImageGuard\Ssim\SsimCalculatorInterface;

use function sprintf;

use const PATHINFO_EXTENSION;

/**
 * Executes the quality-guard retry loop for a single image.
 *
 * Algorithm (per spec):
 *   1. Compress at current quality via the provided callable.
 *   2. Compute SSIM between original and compressed output.
 *   3. If SSIM >= threshold → accept and break.
 *   4. If quality + step > maxQuality → apply onFail behavior and break.
 *   5. Bump quality by step and retry.
 */
final class GuardRunner
{
    public function __construct(
        private readonly SsimCalculatorInterface $calculator,
    ) {
    }

    /**
     * @param callable(int, string): string $compressor receives quality int and original path, returns output file path
     */
    public function run(
        string $originalPath,
        callable $compressor,
        float $threshold,
        int $startQuality,
        int $maxQuality,
        int $qualityStep,
        OnFailBehavior $onFail,
    ): PipelineResult {
        $this->assertValidConfig($qualityStep, $startQuality, $maxQuality);

        $startTime = hrtime(true);
        $originalSize = $this->fileSize($originalPath);
        $warnings = [];

        [$ssim, $outputPath, $quality, $attempts] = $this->executeLoop(
            $originalPath,
            $compressor,
            $threshold,
            $startQuality,
            $maxQuality,
            $qualityStep
        );

        if ($ssim >= $threshold) {
            return $this->buildResult(true, $ssim, $threshold, $quality, $attempts, $originalPath, $outputPath, $originalSize, $startTime, $warnings);
        }

        return $this->handleFailure(
            $onFail,
            $ssim,
            $threshold,
            $quality,
            $attempts,
            $originalPath,
            $outputPath,
            $originalSize,
            $startTime,
            $warnings
        );
    }

    /**
     * @param callable(int, string): string $compressor
     *
     * @return array{float, string, int, int} [ssim, outputPath, quality, attempts]
     */
    private function executeLoop(
        string $originalPath,
        callable $compressor,
        float $threshold,
        int $quality,
        int $maxQuality,
        int $qualityStep,
    ): array {
        $attempts = 0;
        $bestSsim = 0.0;
        $bestOutput = '';
        $bestQuality = $quality;

        while (true) {
            ++$attempts;
            $outputPath = $compressor($quality, $originalPath);
            $ssim = $this->calculator->compare($originalPath, $outputPath);

            if ($ssim > $bestSsim) {
                $bestSsim = $ssim;
                $bestOutput = $outputPath;
                $bestQuality = $quality;
            }

            if ($ssim >= $threshold) {
                return [$ssim, $outputPath, $quality, $attempts];
            }

            $canBump = ($quality + $qualityStep) <= $maxQuality;
            if (!$canBump) {
                break;
            }

            $quality += $qualityStep;
        }

        return [$bestSsim, $bestOutput, $bestQuality, $attempts];
    }

    private function assertValidConfig(int $qualityStep, int $startQuality, int $maxQuality): void
    {
        if ($qualityStep <= 0) {
            throw new InvalidArgumentException('qualityStep must be a positive integer, got '.$qualityStep.'.');
        }
        if ($startQuality > $maxQuality) {
            throw new InvalidArgumentException(sprintf('startQuality (%d) must not exceed maxQuality (%d).', $startQuality, $maxQuality));
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function handleFailure(
        OnFailBehavior $onFail,
        float $bestSsim,
        float $threshold,
        int $bestQuality,
        int $attempts,
        string $originalPath,
        string $bestOutput,
        int $originalSize,
        int $startTime,
        array $warnings,
    ): PipelineResult {
        $message = sprintf(
            'SSIM %.6f did not reach threshold %.2f after %d attempt(s).',
            $bestSsim,
            $threshold,
            $attempts,
        );

        if ($onFail === OnFailBehavior::WARN) {
            $warnings[] = $message;
        }

        $result = $this->buildResult(
            passed: false,
            ssim: $bestSsim,
            threshold: $threshold,
            quality: $bestQuality,
            attempts: $attempts,
            originalPath: $originalPath,
            outputPath: $bestOutput,
            originalSize: $originalSize,
            startTime: $startTime,
            warnings: $warnings,
        );

        if ($onFail === OnFailBehavior::ABORT) {
            throw new ImageQualityException($message, $result);
        }

        return $result;
    }

    /**
     * @param list<string> $warnings
     */
    private function buildResult(
        bool $passed,
        float $ssim,
        float $threshold,
        int $quality,
        int $attempts,
        string $originalPath,
        string $outputPath,
        int $originalSize,
        int $startTime,
        array $warnings,
    ): PipelineResult {
        $compressedSize = $outputPath !== '' ? $this->fileSize($outputPath) : 0;
        $savingsPercent = $originalSize > 0
            ? max(0.0, ($originalSize - $compressedSize) / $originalSize * 100)
            : 0.0;
        $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        return new PipelineResult(
            passed: $passed,
            ssimScore: $ssim,
            threshold: $threshold,
            qualityUsed: $quality,
            attempts: $attempts,
            originalSize: $originalSize,
            compressedSize: $compressedSize,
            savingsPercent: $savingsPercent,
            formatInput: $this->detectFormat($originalPath),
            formatOutput: $outputPath !== '' ? $this->detectFormat($outputPath) : 'unknown',
            durationMs: $durationMs,
            outputPath: $outputPath,
            warnings: $warnings,
        );
    }

    private function fileSize(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }

        $size = filesize($path);

        return $size !== false ? $size : 0;
    }

    private function detectFormat(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'jpeg',
            'png' => 'png',
            'webp' => 'webp',
            'gif' => 'gif',
            'avif' => 'avif',
            'bmp' => 'bmp',
            default => 'unknown',
        };
    }
}
