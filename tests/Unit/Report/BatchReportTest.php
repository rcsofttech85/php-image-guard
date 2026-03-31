<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Report\BatchReport;
use RcSoftTech\ImageGuard\Report\PipelineResult;

use function count;

#[CoversClass(BatchReport::class)]
final class BatchReportTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function makeResult(array $overrides = []): PipelineResult
    {
        $data = array_merge([
            'passed' => true,
            'ssimScore' => 0.95,
            'threshold' => 0.92,
            'qualityUsed' => 75,
            'attempts' => 1,
            'originalSize' => 1_000_000,
            'compressedSize' => 300_000,
            'savingsPercent' => 70.0,
            'formatInput' => 'jpeg',
            'formatOutput' => 'jpeg',
            'durationMs' => 100,
            'outputPath' => '/tmp/out.jpg',
            'warnings' => [],
        ], $overrides);

        return new PipelineResult(
            passed: (bool) $data['passed'],
            ssimScore: (float) $data['ssimScore'],
            threshold: (float) $data['threshold'],
            qualityUsed: (int) $data['qualityUsed'],
            attempts: (int) $data['attempts'],
            originalSize: (int) $data['originalSize'],
            compressedSize: (int) $data['compressedSize'],
            savingsPercent: (float) $data['savingsPercent'],
            formatInput: (string) $data['formatInput'],
            formatOutput: (string) $data['formatOutput'],
            durationMs: (int) $data['durationMs'],
            outputPath: (string) $data['outputPath'],
            warnings: (array) $data['warnings'],
        );
    }

    public function testResultsReturnsAllPipelineResults(): void
    {
        $report = new BatchReport([
            $this->makeResult(['passed' => true]),
            $this->makeResult(['passed' => false]),
        ]);

        $this->assertCount(2, $report->results());
    }

    public function testPassedFiltersToOnlySuccessfulResults(): void
    {
        $report = new BatchReport([
            $this->makeResult(['passed' => true]),
            $this->makeResult(['passed' => false]),
            $this->makeResult(['passed' => true]),
        ]);

        $this->assertCount(2, $report->passed());
    }

    public function testFailedFiltersToOnlyFailedResults(): void
    {
        $report = new BatchReport([
            $this->makeResult(['passed' => true]),
            $this->makeResult(['passed' => false]),
            $this->makeResult(['passed' => true]),
        ]);

        $this->assertCount(1, $report->failed());
    }

    public function testAverageSsimReturnsArithmeticMeanRoundedToSixDecimals(): void
    {
        $report = new BatchReport([
            $this->makeResult(['ssimScore' => 0.9]),
            $this->makeResult(['ssimScore' => 0.98]),
        ]);

        $this->assertEqualsWithDelta(0.94, $report->averageSsim(), 0.000001);
    }

    public function testAverageSsimReturnsZeroOnEmptyReport(): void
    {
        $report = new BatchReport([]);

        $this->assertSame(0.0, $report->averageSsim());
    }

    public function testFailRateReturnsCorrectLabelAndPercentage(): void
    {
        $report = new BatchReport([
            $this->makeResult(['passed' => true]),
            $this->makeResult(['passed' => true]),
            $this->makeResult(['passed' => false]),
        ]);

        $rate = $report->failRate();

        $this->assertStringContainsString('1 of 3 failed', $rate);
        $this->assertStringContainsString('33.3%', $rate);
    }

    public function testFailRateReturnsSentinelStringOnEmptyReport(): void
    {
        $report = new BatchReport([]);

        $this->assertSame('0 of 0 failed (0.0%)', $report->failRate());
    }

    public function testTotalSavedUsesSingularLabelForOneImage(): void
    {
        $report = new BatchReport([$this->makeResult()]);

        $this->assertStringContainsString('1 image', $report->totalSaved());
    }

    public function testTotalSavedUsesPluralLabelForMultipleImages(): void
    {
        $report = new BatchReport([
            $this->makeResult(['originalSize' => 1_048_576, 'compressedSize' => 348_576]),
            $this->makeResult(['originalSize' => 1_048_576, 'compressedSize' => 348_576]),
        ]);

        $this->assertStringContainsString('saved across 2 images', $report->totalSaved());
    }

    #[TestWith([10, 0, '10 B'])]
    #[TestWith([1_536, 0, '1.5 KB'])]
    #[TestWith([1_572_864, 0, '1.5 MB'])]
    #[TestWith([1_610_612_736, 0, '1.5 GB'])]
    public function testTotalSavedFormatsBytesCorrectly(int $original, int $compressed, string $expectedUnit): void
    {
        $report = new BatchReport([
            $this->makeResult(['originalSize' => $original, 'compressedSize' => $compressed]),
        ]);

        $this->assertStringContainsString($expectedUnit, $report->totalSaved());
    }

    public function testToJsonProducesValidJsonWithResultsKey(): void
    {
        $report = new BatchReport([$this->makeResult()]);

        $decoded = json_decode($report->toJson(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('results', $decoded);
        $this->assertCount(1, $decoded['results']);
    }

    public function testToCsvProducesHeaderRowWithSsimScoreColumn(): void
    {
        $report = new BatchReport([$this->makeResult()]);

        $csv = $report->toCsv();
        $lines = array_filter(explode("\n", $csv));
        $firstLine = array_values($lines)[0];

        $this->assertStringContainsString('ssimScore', $firstLine);
    }

    public function testToCsvProducesAtLeastOneDataRowBeyondHeader(): void
    {
        $report = new BatchReport([$this->makeResult()]);

        $lines = array_filter(explode("\n", $report->toCsv()));

        $this->assertGreaterThanOrEqual(2, count($lines));
    }
}
