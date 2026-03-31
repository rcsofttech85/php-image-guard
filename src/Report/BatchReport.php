<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Report;

use JsonException;

use function count;
use function is_string;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * Aggregated report for a batch guard run.
 * Wraps multiple PipelineResult instances and exposes summary statistics.
 */
final readonly class BatchReport
{
    /** @param list<PipelineResult> $results */
    public function __construct(
        private array $results,
    ) {
    }

    /** @return list<PipelineResult> */
    public function results(): array
    {
        return $this->results;
    }

    /** @return list<PipelineResult> */
    public function passed(): array
    {
        return array_values(array_filter($this->results, static fn (PipelineResult $r): bool => $r->passed()));
    }

    /** @return list<PipelineResult> */
    public function failed(): array
    {
        return array_values(array_filter($this->results, static fn (PipelineResult $r): bool => $r->failed()));
    }

    public function averageSsim(): float
    {
        if ($this->results === []) {
            return 0.0;
        }

        $sum = array_sum(array_map(static fn (PipelineResult $r): float => $r->ssimScore, $this->results));

        return round($sum / count($this->results), 6);
    }

    /**
     * Returns a human-readable string representing total bytes saved across the batch.
     * Example: "14.2 MB saved across 23 images".
     */
    public function totalSaved(): string
    {
        $totalSavedBytes = 0;

        foreach ($this->results as $result) {
            $totalSavedBytes += max(0, $result->originalSize - $result->compressedSize);
        }

        $count = count($this->results);
        $saved = $this->formatBytes($totalSavedBytes);

        return sprintf('%s saved across %d %s', $saved, $count, $count === 1 ? 'image' : 'images');
    }

    /**
     * Returns fail rate as a human-readable string.
     * Example: "2 of 23 failed (8.7%)".
     */
    public function failRate(): string
    {
        $total = count($this->results);
        $failed = count($this->failed());

        if ($total === 0) {
            return '0 of 0 failed (0.0%)';
        }

        $pct = round($failed / $total * 100, 1);

        return sprintf('%d of %d failed (%s%%)', $failed, $total, number_format($pct, 1));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => count($this->results),
            'passed' => count($this->passed()),
            'failed' => count($this->failed()),
            'averageSsim' => $this->averageSsim(),
            'failRate' => $this->failRate(),
            'totalSaved' => $this->totalSaved(),
            'results' => array_map(static fn (PipelineResult $r): array => $r->toArray(), $this->results),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Export results as a CSV string (header + one row per image).
     */
    public function toCsv(): string
    {
        $lines = [];
        $header = [
            'passed',
            'ssimScore',
            'threshold',
            'qualityUsed',
            'attempts',
            'originalSize',
            'compressedSize',
            'savingsPercent',
            'formatInput',
            'formatOutput',
            'durationMs',
            'outputPath',
        ];

        $lines[] = implode(',', $header);

        foreach ($this->results as $result) {
            $row = $result->toArray();
            $path = is_string($row['outputPath']) ? $row['outputPath'] : '';
            $lines[] = implode(',', [
                $row['passed'] ? 'true' : 'false',
                $row['ssimScore'],
                $row['threshold'],
                $row['qualityUsed'],
                $row['attempts'],
                $row['originalSize'],
                $row['compressedSize'],
                $row['savingsPercent'],
                $row['formatInput'],
                $row['formatOutput'],
                $row['durationMs'],
                '"'.str_replace('"', '""', $this->sanitizeCsvField($path)).'"',
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return round($bytes / 1_073_741_824, 1).' GB';
        }

        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1).' MB';
        }

        if ($bytes >= 1_024) {
            return round($bytes / 1_024, 1).' KB';
        }

        return $bytes.' B';
    }

    /**
     * Prevent CSV injection by prefixing formula-triggering characters.
     */
    private function sanitizeCsvField(string $value): string
    {
        if ($value !== '' && str_contains('=+-@', $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
