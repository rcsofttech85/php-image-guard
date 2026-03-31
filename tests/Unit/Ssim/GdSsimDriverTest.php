<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Ssim;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Ssim\GdSsimDriver;

use function assert;
use function strlen;

/**
 * Verifies the Gd SSIM implementation against the five accuracy checks
 * defined in the specification (Part 1C).
 *
 * All test fixtures are generated in-memory with GD — no committed binary files.
 */
#[CoversClass(GdSsimDriver::class)]
final class GdSsimDriverTest extends TestCase
{
    private GdSsimDriver $driver;

    /** @var list<string> Temp file paths to clean up after each test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->driver = new GdSsimDriver();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function testIdenticalImagesProduceMaximumSsim(): void
    {
        $path = $this->createColoredImage(100, 100, 120, 80, 60);

        $score = $this->driver->compare($path, $path);

        $this->assertEqualsWithDelta(1.0, $score, 0.000001, 'Identical images must yield SSIM = 1.0');
    }

    public function testWhiteVsBlackImageProducesNearZeroSsim(): void
    {
        $white = $this->createColoredImage(100, 100, 255, 255, 255);
        $black = $this->createColoredImage(100, 100, 0, 0, 0);

        $score = $this->driver->compare($white, $black);

        $this->assertLessThan(0.05, $score, 'White vs black must yield SSIM near 0');
    }

    public function testHighQualityJpegProducesHighSsim(): void
    {
        $original = $this->createColoredImage(200, 200, 100, 150, 200);
        $reEncoded = $this->reencodeJpeg($original, 95);

        $score = $this->driver->compare($original, $reEncoded);

        $this->assertGreaterThan(0.98, $score, 'Original vs quality-95 JPEG must yield SSIM > 0.98');
    }

    public function testLowQualityJpegProducesLowSsim(): void
    {
        $original = $this->createGradientImage(200, 200);
        $degraded = $this->reencodeJpeg($original, 10);

        $score = $this->driver->compare($original, $degraded);

        $this->assertLessThan(0.92, $score, 'Original vs quality-10 JPEG must yield SSIM < 0.92');
    }

    #[RequiresFunction('imagewebp')]
    public function testMediumQualityWebpVsPngSsimInRange(): void
    {
        $original = $this->createGradientImage(200, 200, isPng: true);
        $webp = $this->convertToWebp($original, 50);

        $score = $this->driver->compare($original, $webp);

        $this->assertGreaterThanOrEqual(
            0.85,
            $score,
            'PNG vs 50% WebP SSIM must be >= 0.85'
        );
        $this->assertLessThanOrEqual(
            0.995,
            $score,
            'PNG vs 50% WebP SSIM must be <= 0.995 (some lossy degradation expected)'
        );
    }

    public function testMismatchedDimensionsAreHandledGracefully(): void
    {
        $small = $this->createColoredImage(50, 50, 200, 100, 50);
        $large = $this->createColoredImage(100, 100, 200, 100, 50);

        $score = $this->driver->compare($large, $small);

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testSsimScoreIsAlwaysBetweenZeroAndOne(): void
    {
        $pathA = $this->createGradientImage(100, 100);
        $pathB = $this->createColoredImage(100, 100, 200, 50, 75);

        $score = $this->driver->compare($pathA, $pathB);

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testSsimReturnsSixDecimalPrecision(): void
    {
        $pathA = $this->createGradientImage(100, 100);
        $pathB = $this->reencodeJpeg($pathA, 80);

        $score = $this->driver->compare($pathA, $pathB);

        $parts = explode('.', (string) $score);
        $decimals = isset($parts[1]) ? strlen($parts[1]) : 0;
        $this->assertLessThanOrEqual(6, $decimals);
    }

    /**
     * Create a solid-color JPEG image and return its temp path.
     */
    private function createColoredImage(
        int $width,
        int $height,
        int $r,
        int $g,
        int $b,
        bool $isPng = false,
    ): string {
        $img = imagecreatetruecolor($width, $height);
        assert($img !== false);
        $color = imagecolorallocate($img, $r, $g, $b);
        assert($color !== false);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color);

        $ext = $isPng ? 'png' : 'jpg';
        $path = sys_get_temp_dir().'/img_guard_test_'.bin2hex(random_bytes(8)).'.'.$ext;

        if ($isPng) {
            imagepng($img, $path, 0);
        } else {
            imagejpeg($img, $path, 100);
        }

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Create a gradient PNG/JPEG for realistic texture-based testing.
     */
    private function createGradientImage(int $width, int $height, bool $isPng = false): string
    {
        $img = imagecreatetruecolor($width, $height);
        assert($img !== false);

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $v = (int) (($x / $width) * 200 + ($y / $height) * 55);
                $color = imagecolorallocate($img, $v, (int) ($v * 0.6), (int) ($v * 0.3));
                assert($color !== false);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $ext = $isPng ? 'png' : 'jpg';
        $path = sys_get_temp_dir().'/img_guard_grad_'.bin2hex(random_bytes(8)).'.'.$ext;

        if ($isPng) {
            imagepng($img, $path, 0);
        } else {
            imagejpeg($img, $path, 100);
        }

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Re-encode an existing JPEG at a different quality level.
     */
    private function reencodeJpeg(string $sourcePath, int $quality): string
    {
        $src = imagecreatefromjpeg($sourcePath);
        assert($src !== false);
        $out = sys_get_temp_dir().'/img_guard_q'.$quality.'_'.bin2hex(random_bytes(8)).'.jpg';
        imagejpeg($src, $out, $quality);
        $this->tempFiles[] = $out;

        return $out;
    }

    /**
     * Convert PNG to WebP at given quality.
     */
    private function convertToWebp(string $pngPath, int $quality): string
    {
        $src = imagecreatefrompng($pngPath);
        assert($src !== false);
        $out = sys_get_temp_dir().'/img_guard_webp'.$quality.'_'.bin2hex(random_bytes(8)).'.webp';
        imagewebp($src, $out, $quality);
        $this->tempFiles[] = $out;

        return $out;
    }

    public function testFileNotFoundOrNotReadableThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file not found or not readable');
        $this->driver->compare('/tmp/does_not_exist.jpg', '/tmp/does_not_exist2.jpg');
    }

    public function testImageSmallerThanWindow(): void
    {
        $pathA = $this->createColoredImage(4, 4, 100, 100, 100);
        $pathB = $this->createColoredImage(4, 4, 50, 50, 50);

        $score = $this->driver->compare($pathA, $pathB);
        $this->assertGreaterThanOrEqual(0.0, $score);
    }

    public function testGdFailedToLoadImage(): void
    {
        $path = sys_get_temp_dir().'/invalid_img.jpg';
        file_put_contents($path, 'not an image');
        $this->tempFiles[] = $path;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported image MIME type');
        $this->driver->compare($path, $path);
    }

    #[RequiresFunction('imagegif')]
    #[RequiresFunction('imagecreatefromgif')]
    public function testGifMimeTypeSupported(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $gif = sys_get_temp_dir().'/test.gif';
        imagegif($img, $gif);
        imagedestroy($img);
        $this->tempFiles[] = $gif;

        $score = $this->driver->compare($gif, $gif);
        $this->assertSame(1.0, $score);
    }

    #[RequiresFunction('imagebmp')]
    #[RequiresFunction('imagecreatefrombmp')]
    public function testBmpMimeTypeSupported(): void
    {
        $img = imagecreatetruecolor(10, 10);
        $bmp = sys_get_temp_dir().'/test.bmp';
        imagebmp($img, $bmp);
        imagedestroy($img);
        $this->tempFiles[] = $bmp;

        $score = $this->driver->compare($bmp, $bmp);
        $this->assertSame(1.0, $score);
    }
}
