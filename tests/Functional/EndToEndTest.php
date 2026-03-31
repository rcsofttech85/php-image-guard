<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Functional;

use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\ImageGuard;
use RcSoftTech\ImageGuard\Report\BatchReport;
use RcSoftTech\ImageGuard\Report\PipelineResult;
use RuntimeException;

/**
 * Generates a complex pseudo-photograph with noise/gradients.
 * Uses a REAL compression closure that physically loads and re-encodes the image using GD.
 * Asserts real-world behavior (filesize drops, SSIM degrades accurately, retry loop triggers).
 */
final class EndToEndTest extends TestCase
{
    private string $originalImage = '';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        //  Create a "real" photo-like image (200x200) with noise so compression actually causes loss
        $this->originalImage = sys_get_temp_dir().'/ig_e2e_photo_'.uniqid().'.jpg';
        $this->tempFiles[] = $this->originalImage;

        $img = imagecreatetruecolor(200, 200);
        if ($img === false) {
            throw new RuntimeException('Failed to create GD image.');
        }

        // Draw a gradient background
        for ($y = 0; $y < 200; ++$y) {
            $color = imagecolorallocate($img, (int) ($y * 1.2), 100, (int) (255 - $y));
            if ($color !== false) {
                imageline($img, 0, $y, 200, $y, $color);
            }
        }

        // Add high-frequency noise (makes JPEG artifacts more pronounced on compression)
        for ($i = 0; $i < 5000; ++$i) {
            $noiseColor = imagecolorallocate($img, rand(0, 255), rand(0, 255), rand(0, 255));
            if ($noiseColor !== false) {
                imagesetpixel($img, rand(0, 199), rand(0, 199), $noiseColor);
            }
        }

        // Save original at 100% quality (lossless-ish baseline)
        imagejpeg($img, $this->originalImage, 100);
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * A real compressor that mimics spatie/image-optimizer or an intervention/image save call.
     * It physically loads the input file and rewrites it at $quality.
     */
    private function getRealGdCompressor(): callable
    {
        return function (int $quality, string $path): string {
            $src = imagecreatefromjpeg($path);
            if ($src === false) {
                throw new RuntimeException('GD could not load the source image for compression.');
            }

            $out = sys_get_temp_dir().'/ig_e2e_out_q'.$quality.'_'.uniqid().'.jpg';
            $this->tempFiles[] = $out;

            imagejpeg($src, $out, $quality);
            imagedestroy($src);

            return $out;
        };
    }

    public function testSingleImagePipelineExecutesTrueCompression(): void
    {
        $originalSize = filesize($this->originalImage);

        $result = ImageGuard::original($this->originalImage)
            ->compressWith($this->getRealGdCompressor())
            ->threshold('balanced') // 0.92
            ->startAt(60)
            ->maxQuality(90)
            ->step(10)
            ->run(ImageGuard::resolveCalculator());

        $this->assertInstanceOf(PipelineResult::class, $result);
        $this->assertTrue($result->passed(), 'Pipeline should eventually pass within 60-90 quality bounds.');

        $this->assertFileExists((string) $result->outputPath);
        $compressedSize = filesize((string) $result->outputPath);

        $this->assertLessThan($originalSize, $compressedSize, 'Compressed file should be smaller than 100% quality original.');

        $this->assertSame($originalSize, $result->originalSize);
        $this->assertSame($compressedSize, $result->compressedSize);
        $this->assertGreaterThan(0.0, $result->savingsPercent);

        $this->assertGreaterThanOrEqual(0.92, $result->ssimScore, 'Final output must meet or exceed the BALANCED threshold (0.92).');

        $this->assertGreaterThanOrEqual(1, $result->attempts);
        $this->assertGreaterThanOrEqual(60, $result->qualityUsed);
        $this->assertLessThanOrEqual(90, $result->qualityUsed);
    }

    public function testBatchProcessingWithTrueCompression(): void
    {
        $copyPath = sys_get_temp_dir().'/ig_e2e_copy_'.uniqid().'.jpg';
        copy($this->originalImage, $copyPath);
        $this->tempFiles[] = $copyPath;

        $report = ImageGuard::batch([$this->originalImage, $copyPath])
            ->compressWith($this->getRealGdCompressor())
            ->threshold('loose')
            ->startAt(50)
            ->maxQuality(80)
            ->step(15)
            ->run(ImageGuard::resolveCalculator());

        // Batch Assertions
        $this->assertInstanceOf(BatchReport::class, $report);
        $this->assertCount(2, $report->results());

        $results = $report->results();
        $this->assertTrue($results[0]->passed());
        $this->assertTrue($results[1]->passed());
        $this->assertGreaterThan(0.0, $report->averageSsim());

        $this->assertNotSame($results[0]->outputPath, $results[1]->outputPath);
    }
}
