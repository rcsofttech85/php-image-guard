<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Report\PipelineResult;

#[CoversClass(PipelineResult::class)]
final class PipelineResultTest extends TestCase
{
    private function makeResult(
        bool $passed = true,
        float $ssimScore = 0.961423,
        float $threshold = 0.92,
        int $qualityUsed = 75,
        int $attempts = 2,
        int $originalSize = 1_000_000,
        int $compressedSize = 320_000,
        float $savingsPercent = 68.0,
        string $formatInput = 'jpeg',
        string $formatOutput = 'jpeg',
        int $durationMs = 143,
        string $outputPath = '/tmp/out.jpg',
        array $warnings = [],
    ): PipelineResult {
        return new PipelineResult(
            passed: $passed,
            ssimScore: $ssimScore,
            threshold: $threshold,
            qualityUsed: $qualityUsed,
            attempts: $attempts,
            originalSize: $originalSize,
            compressedSize: $compressedSize,
            savingsPercent: $savingsPercent,
            formatInput: $formatInput,
            formatOutput: $formatOutput,
            durationMs: $durationMs,
            outputPath: $outputPath,
            warnings: $warnings,
        );
    }

    public function testPassedReturnsTrueWhenPipelineSucceeded(): void
    {
        $result = $this->makeResult(passed: true);

        $this->assertTrue($result->passed());
    }

    public function testPassedReturnsFalseWhenPipelineFailed(): void
    {
        $result = $this->makeResult(passed: false);

        $this->assertFalse($result->passed());
    }

    public function testFailedIsStrictInverseOfPassedForSuccess(): void
    {
        $this->assertFalse($this->makeResult(passed: true)->failed());
    }

    public function testFailedIsStrictInverseOfPassedForFailure(): void
    {
        $this->assertTrue($this->makeResult(passed: false)->failed());
    }

    // savings()

    #[TestWith([68.4, '68%'])]
    #[TestWith([68.5, '69%'])]
    #[TestWith([0.0, '0%'])]
    #[TestWith([100.0, '100%'])]
    public function testSavingsReturnsRoundedPercentageString(float $percent, string $expected): void
    {
        $result = $this->makeResult(savingsPercent: $percent);

        $actual = $result->savings();

        $this->assertSame($expected, $actual);
    }

    public function testSummaryContainsPassedLabelOnSuccess(): void
    {
        $result = $this->makeResult(passed: true);

        $summary = $result->summary();

        $this->assertStringContainsString('Passed', $summary);
    }

    public function testSummaryContainsFailedLabelOnFailure(): void
    {
        $result = $this->makeResult(passed: false);

        $summary = $result->summary();

        $this->assertStringContainsString('Failed', $summary);
    }

    public function testSummaryContainsSsimScoreQualityAndDuration(): void
    {
        $result = $this->makeResult(ssimScore: 0.961423, qualityUsed: 75, durationMs: 143);

        $summary = $result->summary();

        $this->assertStringContainsString('0.961', $summary);
        $this->assertStringContainsString('75', $summary);
        $this->assertStringContainsString('143ms', $summary);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function expectedArrayKeys(): iterable
    {
        yield 'passed' => ['passed'];
        yield 'ssimScore' => ['ssimScore'];
        yield 'threshold' => ['threshold'];
        yield 'qualityUsed' => ['qualityUsed'];
        yield 'attempts' => ['attempts'];
        yield 'originalSize' => ['originalSize'];
        yield 'compressedSize' => ['compressedSize'];
        yield 'savingsPercent' => ['savingsPercent'];
        yield 'formatInput' => ['formatInput'];
        yield 'formatOutput' => ['formatOutput'];
        yield 'durationMs' => ['durationMs'];
        yield 'outputPath' => ['outputPath'];
        yield 'warnings' => ['warnings'];
    }

    #[DataProvider('expectedArrayKeys')]
    public function testToArrayContainsRequiredKey(string $key): void
    {
        $arr = $this->makeResult()->toArray();

        $this->assertArrayHasKey($key, $arr);
    }

    public function testToJsonProducesValidJson(): void
    {
        $result = $this->makeResult();

        $decoded = json_decode($result->toJson(), true);

        $this->assertIsArray($decoded);
    }

    public function testToJsonPreservesSsimScoreValue(): void
    {
        $result = $this->makeResult(ssimScore: 0.961423);

        $decoded = json_decode($result->toJson(), true);

        $this->assertSame(0.961423, $decoded['ssimScore']);
    }

    public function testToJsonRoundTripsScalarFields(): void
    {
        $result = $this->makeResult();
        $arr = $result->toArray();

        $decoded = json_decode($result->toJson(), true);

        $this->assertSame($arr['passed'], $decoded['passed']);
        $this->assertSame($arr['qualityUsed'], $decoded['qualityUsed']);
        $this->assertSame($arr['formatInput'], $decoded['formatInput']);
    }

    public function testWarningsAreAccessibleViaProperty(): void
    {
        $message = 'SSIM below threshold after 3 attempts.';
        $result = $this->makeResult(warnings: [$message]);

        $this->assertCount(1, $result->warnings);
        $this->assertSame($message, $result->warnings[0]);
    }
}
