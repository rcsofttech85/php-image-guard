<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Ssim;

use GdImage;
use InvalidArgumentException;
use Override;
use RuntimeException;

use function sprintf;

use const IMG_BICUBIC;

/**
 * Pure PHP SSIM implementation using the GD extension.
 *
 * Grayscale conversion: ITU-R BT.601 Y = 0.299R + 0.587G + 0.114B
 * Stabilisation constants: C1 = 6.5025, C2 = 58.5225 (L=255)
 */
final class GdSsimDriver extends AbstractSsimDriver
{
    #[Override]
    protected function c1(): float
    {
        // (0.01 × 255)²
        return 6.5025;
    }

    #[Override]
    protected function c2(): float
    {
        // (0.03 × 255)²
        return 58.5225;
    }

    #[Override]
    public function compare(string $pathA, string $pathB): float
    {
        $imgA = $this->loadImage($pathA);
        $imgB = $this->loadImage($pathB);

        try {
            $width = imagesx($imgA);
            $height = imagesy($imgA);

            if ($width !== imagesx($imgB) || $height !== imagesy($imgB)) {
                $resized = imagescale($imgB, $width, $height, IMG_BICUBIC);
                if ($resized === false) {
                    throw new RuntimeException('Failed to resize image B to match image A dimensions.');
                }
                imagedestroy($imgB);
                $imgB = $resized;
            }

            $grayA = $this->toGrayscaleMap($imgA, $width, $height);
            $grayB = $this->toGrayscaleMap($imgB, $width, $height);
        } finally {
            imagedestroy($imgA);
            imagedestroy($imgB);
        }

        return $this->computeMeanSsim($grayA, $grayB, $width, $height);
    }

    /**
     * Load any supported image format by inspecting MIME type.
     */
    private function loadImage(string $path): GdImage
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Image file not found or not readable: %s', $path));
        }

        $mime = mime_content_type($path);

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            'image/webp' => imagecreatefromwebp($path),
            'image/gif' => imagecreatefromgif($path),
            'image/bmp' => imagecreatefrombmp($path),
            'image/avif' => imagecreatefromavif($path),
            default => throw new InvalidArgumentException(sprintf('Unsupported image MIME type "%s" for file: %s', $mime, $path)),
        };

        if ($image === false) {
            throw new RuntimeException(sprintf('GD failed to load image: %s', $path));
        }

        return $image;
    }

    /**
     * Extract per-pixel luminance values (0–255) using ITU-R BT.601.
     * Returns a flat array indexed as [$y * $width + $x].
     *
     * @return array<int, float>
     */
    private function toGrayscaleMap(GdImage $image, int $width, int $height): array
    {
        $map = [];

        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgb = imagecolorat($image, $x, $y);
                if ($rgb === false) {
                    $map[$y * $width + $x] = 0.0;

                    continue;
                }
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // ITU-R BT.601 luma
                $map[$y * $width + $x] = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            }
        }

        return $map;
    }
}
