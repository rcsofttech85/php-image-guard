<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Guard;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Enums\OnFailBehavior;
use RcSoftTech\ImageGuard\Exceptions\ImageQualityException;
use RcSoftTech\ImageGuard\Guard\GuardRunner;
use RcSoftTech\ImageGuard\Report\PipelineResult;
use RcSoftTech\ImageGuard\Ssim\SsimCalculatorInterface;

use function assert;

#[CoversClass(GuardRunner::class)]
final class GuardRunnerTest extends TestCase
{
    private string $originalPath = '';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        // A real 150×150 JPEG gives filesize() a real value for savingsPercent tests
        $this->originalPath = $this->createTempJpeg(150, 150, 120, 80, 40);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createTempJpeg(int $w, int $h, int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor($w, $h);
        assert($img !== false);
        $color = imagecolorallocate($img, $r, $g, $b);
        assert($color !== false);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $color);
        $path = sys_get_temp_dir().'/runner_test_'.uniqid().'.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Stub that returns a fixed SSIM score on every call.
     * Use createStub() — no behavior expectations, only return values.
     */
    private function stubCalculator(float $score): SsimCalculatorInterface
    {
        $stub = $this->createStub(SsimCalculatorInterface::class);
        $stub->method('compare')->willReturn($score);

        return $stub;
    }

    /**
     * Stub that returns successive scores from the given sequence.
     *
     * @param list<float> $scores
     */
    private function stubCalculatorSequence(array $scores): SsimCalculatorInterface
    {
        $stub = $this->createStub(SsimCalculatorInterface::class);
        $stub->method('compare')->willReturnOnConsecutiveCalls(...$scores);

        return $stub;
    }

    /**
     * Returns a compressor that re-encodes $this->originalPath at the given quality.
     */
    private function makeJpegCompressor(): callable
    {
        $original = $this->originalPath;

        return function (int $quality, string $path): string {
            $src = imagecreatefromjpeg($path);
            assert($src !== false);
            $out = sys_get_temp_dir().'/runner_out_q'.$quality.'_'.uniqid().'.jpg';
            imagejpeg($src, $out, $quality);
            imagedestroy($src);
            $this->tempFiles[] = $out;

            return $out;
        };
    }

    /**
     * Returns a compressor that always yields a non-existent path with the given extension.
     * Used to probe format detection without touching the filesystem.
     */
    private function makePhantomCompressor(string $extension): callable
    {
        return static fn (int $quality, string $path): string => "/tmp/phantom_{$quality}.{$extension}";
    }

    /**
     * Shared run parameters for the happy path — keeps tests DRY without hidden magic.
     *
     * @return array<string, mixed>
     */
    private function defaultRunArgs(): array
    {
        return [
            'threshold' => 0.92,
            'startQuality' => 75,
            'maxQuality' => 95,
            'qualityStep' => 5,
            'onFail' => OnFailBehavior::RECOMPRESS,
        ];
    }

    // Core retry loop behaviour

    public function testRunThrowsWhenQualityStepIsZeroOrNegative(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('qualityStep must be a positive integer');

        $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 75,
            maxQuality: 95,
            qualityStep: 0,
            onFail: OnFailBehavior::RECOMPRESS,
        );
    }

    public function testRunThrowsWhenStartQualityExceedsMaxQuality(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('startQuality (90) must not exceed maxQuality (80)');

        $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 90,
            maxQuality: 80,
            qualityStep: 5,
            onFail: OnFailBehavior::RECOMPRESS,
        );
    }

    public function testPassesOnFirstAttemptWhenSsimExceedsThreshold(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.97));
        $args = $this->defaultRunArgs();

        $result = $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: $args['threshold'],
            startQuality: $args['startQuality'],
            maxQuality: $args['maxQuality'],
            qualityStep: $args['qualityStep'],
            onFail: $args['onFail'],
        );

        $this->assertTrue($result->passed());
        $this->assertSame(1, $result->attempts);
        $this->assertSame(75, $result->qualityUsed);
    }

    public function testRetriesAndPassesOnSecondAttempt(): void
    {
        $runner = new GuardRunner($this->stubCalculatorSequence([0.85, 0.95]));
        $args = $this->defaultRunArgs();

        $result = $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: $args['threshold'],
            startQuality: 75,
            maxQuality: $args['maxQuality'],
            qualityStep: 5,
            onFail: $args['onFail'],
        );

        $this->assertTrue($result->passed());
        $this->assertSame(2, $result->attempts);
        $this->assertSame(80, $result->qualityUsed); // 75 + 5
    }

    public function testRecompressBehaviorAcceptsBestResultWithoutThrowing(): void
    {
        $runner = new GuardRunner($this->stubCalculatorSequence([0.80, 0.84, 0.87]));

        $result = $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 75,
            maxQuality: 85, // exactly 3 attempts: 75, 80, 85
            qualityStep: 5,
            onFail: OnFailBehavior::RECOMPRESS,
        );

        $this->assertFalse($result->passed());
        $this->assertSame(3, $result->attempts);
    }

    public function testAbortBehaviorThrowsImageQualityException(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.80));

        $this->expectException(ImageQualityException::class);

        $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 95,
            maxQuality: 95,
            qualityStep: 5,
            onFail: OnFailBehavior::ABORT,
        );
    }

    public function testAbortExceptionCarriesFailedPipelineResult(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.80));

        $exception = null;

        try {
            $runner->run(
                originalPath: $this->originalPath,
                compressor: $this->makeJpegCompressor(),
                threshold: 0.92,
                startQuality: 95,
                maxQuality: 95,
                qualityStep: 5,
                onFail: OnFailBehavior::ABORT,
            );
        } catch (ImageQualityException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, 'Expected ImageQualityException was not thrown.');
        $this->assertInstanceOf(PipelineResult::class, $exception->result);
        $this->assertFalse($exception->result->passed());
    }

    public function testWarnBehaviorReturnsFailedResultWithWarningMessage(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.80));

        $result = $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 95,
            maxQuality: 95,
            qualityStep: 5,
            onFail: OnFailBehavior::WARN,
        );

        $this->assertFalse($result->passed());
        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('threshold', $result->warnings[0]);
    }

    public function testDurationMsIsNonNegative(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $result = $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 75,
            maxQuality: 95,
            qualityStep: 5,
            onFail: OnFailBehavior::RECOMPRESS,
        );

        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function testSavingsPercentIsWithinValidBounds(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $result = $runner->run(
            originalPath: $this->originalPath,
            compressor: $this->makeJpegCompressor(),
            threshold: 0.92,
            startQuality: 10,
            maxQuality: 95,
            qualityStep: 5,
            onFail: OnFailBehavior::RECOMPRESS,
        );

        $this->assertGreaterThanOrEqual(0.0, $result->savingsPercent);
        $this->assertLessThanOrEqual(100.0, $result->savingsPercent);
    }

    public function testOriginalSizeIsZeroWhenOriginalFileDoesNotExist(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $result = $runner->run(
            originalPath: '/tmp/non_existent_original.webp',
            compressor: $this->makePhantomCompressor('gif'),
            threshold: 0.92,
            startQuality: 10,
            maxQuality: 95,
            qualityStep: 10,
            onFail: OnFailBehavior::WARN,
        );

        $this->assertSame(0, $result->originalSize);
    }

    public function testSavingsPercentIsZeroWhenOriginalSizeIsZero(): void
    {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $result = $runner->run(
            originalPath: '/tmp/non_existent_original.webp',
            compressor: $this->makePhantomCompressor('gif'),
            threshold: 0.92,
            startQuality: 10,
            maxQuality: 95,
            qualityStep: 10,
            onFail: OnFailBehavior::WARN,
        );

        $this->assertSame(0.0, $result->savingsPercent);
    }

    #[TestWith(['input.jpg', 'output.jpg', 'jpeg'])]
    #[TestWith(['input.jpeg', 'output.jpeg', 'jpeg'])]
    #[TestWith(['input.png', 'output.png', 'png'])]
    #[TestWith(['input.webp', 'output.webp', 'webp'])]
    #[TestWith(['input.gif', 'output.gif', 'gif'])]
    #[TestWith(['input.avif', 'output.avif', 'avif'])]
    #[TestWith(['input.bmp', 'output.bmp', 'bmp'])]
    #[TestWith(['input.heic', 'output.heic', 'unknown'])]
    public function testDetectFormatResolvesCorrectFormatString(
        string $inputPath,
        string $outputPath,
        string $expectedFormat,
    ): void {
        $runner = new GuardRunner($this->stubCalculator(0.95));

        $result = $runner->run(
            originalPath: $inputPath,
            compressor: static fn (int $q, string $p) => $outputPath,
            threshold: 0.92,
            startQuality: 10,
            maxQuality: 95,
            qualityStep: 10,
            onFail: OnFailBehavior::WARN,
        );

        $this->assertSame($expectedFormat, $result->formatInput);
        $this->assertSame($expectedFormat, $result->formatOutput);
    }
}
