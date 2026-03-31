<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Enums\OnFailBehavior;
use RcSoftTech\ImageGuard\ImageGuard;
use RcSoftTech\ImageGuard\Report\BatchReport;
use RcSoftTech\ImageGuard\Report\PipelineResult;

use function assert;
use function count;

/**
 * End-to-end integration tests exercising ImageGuard's three public APIs
 * with real GD-generated files and real SSIM calculations.
 */
#[CoversClass(ImageGuard::class)]
final class ImageGuardTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function createJpeg(int $w, int $h, int $r, int $g, int $b, int $quality = 95): string
    {
        $img = imagecreatetruecolor($w, $h);
        assert($img !== false);
        $color = imagecolorallocate($img, $r, $g, $b);
        assert($color !== false);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $color);
        $path = sys_get_temp_dir().'/ig_integ_'.uniqid().'.jpg';
        imagejpeg($img, $path, $quality);
        imagedestroy($img);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function makeCompressor(int $r, int $g, int $b): callable
    {
        return function (int $quality, string $path) use ($r, $g, $b): string {
            return $this->createJpeg(100, 100, $r, $g, $b, $quality);
        };
    }

    public function testCompareIdenticalFilesReturnsOne(): void
    {
        $path = $this->createJpeg(100, 100, 128, 128, 128);
        $score = ImageGuard::compare($path, $path);

        $this->assertEqualsWithDelta(1.0, $score, 0.000001);
    }

    public function testCompareReturnsFloat(): void
    {
        $a = $this->createJpeg(100, 100, 200, 100, 50);
        $b = $this->createJpeg(100, 100, 50, 200, 100);

        $score = ImageGuard::compare($a, $b);

        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testCheckReturnsPipelineResult(): void
    {
        $original = $this->createJpeg(100, 100, 100, 150, 200);
        $compressor = $this->makeCompressor(100, 150, 200);

        $result = ImageGuard::check($original, $compressor, 0.85);

        $this->assertInstanceOf(PipelineResult::class, $result);
        $this->assertGreaterThan(0, $result->attempts);
        $this->assertNotEmpty($result->outputPath);
    }

    public function testFluentChainProducesPipelineResult(): void
    {
        $original = $this->createJpeg(100, 100, 80, 120, 160);
        $compressor = $this->makeCompressor(80, 120, 160);

        $result = ImageGuard::original($original)
            ->compressWith($compressor)
            ->threshold('balanced')
            ->startAt(70)
            ->maxQuality(95)
            ->step(5)
            ->onFail(OnFailBehavior::WARN)
            ->run(ImageGuard::resolveCalculator());

        $this->assertInstanceOf(PipelineResult::class, $result);
        $this->assertSame('jpeg', $result->formatInput);
        $this->assertSame('jpeg', $result->formatOutput);
        $this->assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function testFluentChainWithStringThreshold(): void
    {
        $original = $this->createJpeg(100, 100, 40, 80, 120);
        $compressor = $this->makeCompressor(40, 80, 120);

        $result = ImageGuard::original($original)
            ->compressWith($compressor)
            ->threshold('loose')
            ->run(ImageGuard::resolveCalculator());

        $this->assertInstanceOf(PipelineResult::class, $result);
        $this->assertSame(0.85, $result->threshold);
    }

    public function testRunThrowsWithoutCompressor(): void
    {
        $this->expectException(LogicException::class);

        ImageGuard::original('/tmp/does-not-matter.jpg')
            ->threshold(0.92)
            ->run(ImageGuard::resolveCalculator());
    }

    public function testBatchRunReturnsBatchReport(): void
    {
        $paths = [
            $this->createJpeg(80, 80, 255, 0, 0),
            $this->createJpeg(80, 80, 0, 255, 0),
        ];

        $report = ImageGuard::batch($paths)
            ->compressWith(function (int $quality, string $path): string {
                return $this->createJpeg(80, 80, 100, 100, 100, $quality);
            })
            ->threshold(0.0)      // always passes (threshold = 0)
            ->onFail(OnFailBehavior::WARN)
            ->run(ImageGuard::resolveCalculator());

        $this->assertInstanceOf(BatchReport::class, $report);
        $this->assertCount(2, $report->results());
    }

    public function testBatchReportToCsvContainsRows(): void
    {
        $paths = [
            $this->createJpeg(80, 80, 100, 100, 100),
        ];

        $report = ImageGuard::batch($paths)
            ->compressWith(function (int $quality, string $path): string {
                return $this->createJpeg(80, 80, 100, 100, 100, $quality);
            })
            ->threshold(0.0)
            ->run(ImageGuard::resolveCalculator());

        assert($report instanceof BatchReport);

        $csv = $report->toCsv();
        $lines = array_values(array_filter(explode("\n", $csv)));
        $this->assertGreaterThanOrEqual(2, count($lines));
    }
}
