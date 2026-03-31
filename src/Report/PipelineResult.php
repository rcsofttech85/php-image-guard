<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Report;

use JsonException;

use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Immutable value object describing the outcome of a single guard pipeline run.
 */
final readonly class PipelineResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public bool $passed,
        public float $ssimScore,
        public float $threshold,
        public int $qualityUsed,
        public int $attempts,
        public int $originalSize,
        public int $compressedSize,
        public float $savingsPercent,
        public string $formatInput,
        public string $formatOutput,
        public int $durationMs,
        public string $outputPath,
        public array $warnings,
    ) {
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function failed(): bool
    {
        return !$this->passed;
    }

    public function savings(): string
    {
        return round($this->savingsPercent).'%';
    }

    /**
     * Returns a one-line human-readable summary.
     * Example: "Passed (SSIM: 0.961, Quality: 75, Saved: 68%, 143ms)".
     */
    public function summary(): string
    {
        $status = $this->passed ? 'Passed' : 'Failed';
        $ssim = number_format($this->ssimScore, 3);
        $saved = $this->savings();
        $dur = $this->durationMs.'ms';

        return sprintf('%s (SSIM: %s, Quality: %d, Saved: %s, %s)', $status, $ssim, $this->qualityUsed, $saved, $dur);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'ssimScore' => $this->ssimScore,
            'threshold' => $this->threshold,
            'qualityUsed' => $this->qualityUsed,
            'attempts' => $this->attempts,
            'originalSize' => $this->originalSize,
            'compressedSize' => $this->compressedSize,
            'savingsPercent' => round($this->savingsPercent, 2),
            'formatInput' => $this->formatInput,
            'formatOutput' => $this->formatOutput,
            'durationMs' => $this->durationMs,
            'outputPath' => $this->outputPath,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
