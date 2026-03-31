<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Guard;

use RcSoftTech\ImageGuard\Enums\OnFailBehavior;
use RcSoftTech\ImageGuard\Report\BatchReport;

/**
 * Runs guard checks across a list of image paths and aggregates results.
 */
final class BatchRunner
{
    public function __construct(
        private readonly GuardRunner $runner,
    ) {
    }

    /**
     * @param list<string>                  $originalPaths
     * @param callable(int, string): string $compressor
     */
    public function run(
        array $originalPaths,
        callable $compressor,
        float $threshold,
        int $startQuality,
        int $maxQuality,
        int $qualityStep,
        OnFailBehavior $onFail,
    ): BatchReport {
        $results = [];

        foreach ($originalPaths as $originalPath) {
            $results[] = $this->runner->run(
                originalPath: $originalPath,
                compressor: $compressor,
                threshold: $threshold,
                startQuality: $startQuality,
                maxQuality: $maxQuality,
                qualityStep: $qualityStep,
                onFail: $onFail,
            );
        }

        return new BatchReport($results);
    }
}
