<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Guard;

use InvalidArgumentException;
use LogicException;
use RcSoftTech\ImageGuard\Enums\OnFailBehavior;
use RcSoftTech\ImageGuard\Enums\QualityPreset;
use RcSoftTech\ImageGuard\Report\BatchReport;
use RcSoftTech\ImageGuard\Report\PipelineResult;
use RcSoftTech\ImageGuard\Ssim\SsimCalculatorInterface;

use function is_string;
use function sprintf;

/**
 * Fluent builder for configuring and executing quality-guard pipelines.
 *
 * Supports both single-image and batch modes.
 *
 * Usage — single image:
 *   ImageGuard::original('photo.jpg')
 *       ->compressWith($compressor)
 *       ->threshold(0.92)
 *       ->run();
 *
 * Usage — batch:
 *   ImageGuard::batch(glob('uploads/*.jpg'))
 *       ->compressWith($compressor)
 *       ->threshold('balanced')
 *       ->run();
 */
final class GuardBuilder
{
    private const float DEFAULT_THRESHOLD = 0.92;

    private const int DEFAULT_START = 75;

    private const int DEFAULT_MAX_QUALITY = 95;

    private const int DEFAULT_STEP = 5;

    /** @var list<string> */
    private array $paths;

    private bool $isBatch;

    /** @var callable(int, string): string|null */
    private mixed $compressor = null;

    private float $threshold = self::DEFAULT_THRESHOLD;

    private int $startAt = self::DEFAULT_START;

    private int $maxQuality = self::DEFAULT_MAX_QUALITY;

    private int $step = self::DEFAULT_STEP;

    private OnFailBehavior $onFail = OnFailBehavior::RECOMPRESS;

    /**
     * @param list<string> $paths
     */
    private function __construct(array $paths, bool $isBatch)
    {
        $this->paths = $paths;
        $this->isBatch = $isBatch;
    }

    public static function forSingle(string $path): self
    {
        return new self([$path], false);
    }

    /** @param list<string> $paths */
    public static function forBatch(array $paths): self
    {
        return new self(array_values($paths), true);
    }

    /** @param callable(int, string): string $compressor */
    public function compressWith(callable $compressor): self
    {
        $this->compressor = $compressor;

        return $this;
    }

    /**
     * Accept a float threshold or a named preset string.
     * Valid strings: 'strict' (0.97), 'balanced' (0.92), 'loose' (0.85).
     */
    public function threshold(float|string $threshold): self
    {
        $this->threshold = is_string($threshold)
            ? QualityPreset::fromString($threshold)->toFloat()
            : $threshold;

        return $this;
    }

    public function startAt(int $quality): self
    {
        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException(sprintf('startAt quality must be between 1 and 100, got %d.', $quality));
        }

        $this->startAt = $quality;

        return $this;
    }

    public function maxQuality(int $quality): self
    {
        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException(sprintf('maxQuality must be between 1 and 100, got %d.', $quality));
        }

        $this->maxQuality = $quality;

        return $this;
    }

    public function step(int $step): self
    {
        if ($step <= 0) {
            throw new InvalidArgumentException(sprintf('Step must be a positive integer, got %d.', $step));
        }

        $this->step = $step;

        return $this;
    }

    public function onFail(OnFailBehavior $behavior): self
    {
        $this->onFail = $behavior;

        return $this;
    }

    // Execution

    /**
     * Execute the pipeline.
     *
     * @return PipelineResult|BatchReport
     *                                    Returns PipelineResult for single-image mode, BatchReport for batch mode
     */
    public function run(SsimCalculatorInterface $calculator): PipelineResult|BatchReport
    {
        if ($this->compressor === null) {
            throw new LogicException('A compressor callable must be provided via ->compressWith() before calling ->run().');
        }

        $runner = new GuardRunner($calculator);

        if ($this->isBatch) {
            $batchRunner = new BatchRunner($runner);

            return $batchRunner->run(
                originalPaths: $this->paths,
                compressor: $this->compressor,
                threshold: $this->threshold,
                startQuality: $this->startAt,
                maxQuality: $this->maxQuality,
                qualityStep: $this->step,
                onFail: $this->onFail,
            );
        }

        return $runner->run(
            originalPath: $this->paths[0],
            compressor: $this->compressor,
            threshold: $this->threshold,
            startQuality: $this->startAt,
            maxQuality: $this->maxQuality,
            qualityStep: $this->step,
            onFail: $this->onFail,
        );
    }
}
