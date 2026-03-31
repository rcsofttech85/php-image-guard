<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Ssim;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Ssim\ImagickSsimDriver;

#[CoversClass(ImagickSsimDriver::class)]
#[RequiresPhpExtension('imagick')]
final class ImagickSsimDriverTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function createJpeg(): string
    {
        $path = sys_get_temp_dir().'/isd_'.uniqid().'.jpg';
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $path);
        imagedestroy($img);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testCompareReturnsSsimScoreWithinUnitRange(): void
    {
        $driver = new ImagickSsimDriver();
        $score = $driver->compare($this->createJpeg(), $this->createJpeg());

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }
}
