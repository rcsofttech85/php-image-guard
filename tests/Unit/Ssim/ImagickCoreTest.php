<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Tests\Unit\Ssim;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RcSoftTech\ImageGuard\Ssim\ImagickCore;

#[CoversClass(ImagickCore::class)]
#[RequiresPhpExtension('imagick')]
final class ImagickCoreTest extends TestCase
{
    private ImagickCore $core;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->core = new ImagickCore();
    }

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
        $path = sys_get_temp_dir().'/ic_'.uniqid().'.jpg';
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $path);
        imagedestroy($img);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testCompareReturnsSsimScoreWithinUnitRange(): void
    {
        $score = $this->core->compare($this->createJpeg(), $this->createJpeg());

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testCompareThrowsWhenFirstPathDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->core->compare('/does/not/exist.jpg', $this->createJpeg());
    }

    public function testCompareThrowsWhenSecondPathDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->core->compare($this->createJpeg(), '/does/not/exist.jpg');
    }
}
