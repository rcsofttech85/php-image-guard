<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Ssim;

use Imagick;
use InvalidArgumentException;
use Override;

use function sprintf;

/**
 * High-precision SSIM driver backed by the Imagick extension.
 *
 * Uses Imagick::exportImagePixels() which returns float channel data (0.0–1.0),
 * giving sub-integer luminance precision superior to GD's 8-bit integer channels.
 *
 * This class must only be instantiated when ext-imagick is present.
 * Use ImagickSsimDriver as the public facade — it handles the fallback.
 *
 * @internal
 */
final class ImagickCore extends AbstractSsimDriver
{
    #[Override]
    protected function c1(): float
    {
        // (0.01 × 1.0)²
        return 0.0001;
    }

    #[Override]
    protected function c2(): float
    {
        // (0.03 × 1.0)²
        return 0.0009;
    }

    #[Override]
    public function compare(string $pathA, string $pathB): float
    {
        if (!is_file($pathA) || !is_readable($pathA)) {
            throw new InvalidArgumentException(sprintf('Image file not found or not readable: %s', $pathA));
        }
        if (!is_file($pathB) || !is_readable($pathB)) {
            throw new InvalidArgumentException(sprintf('Image file not found or not readable: %s', $pathB));
        }

        $imgA = new Imagick($pathA);
        $imgB = new Imagick($pathB);

        $width = $imgA->getImageWidth();
        $height = $imgA->getImageHeight();

        // Resize B to match A dimensions if needed.
        if ($width !== $imgB->getImageWidth() || $height !== $imgB->getImageHeight()) {
            $imgB->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
        }

        $imgA->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $imgB->transformImageColorspace(Imagick::COLORSPACE_GRAY);

        // exportImagePixels returns float[] of channel values in [0.0, 1.0]
        /** @var list<float> $pixelsA */
        $pixelsA = $imgA->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_FLOAT);

        /** @var list<float> $pixelsB */
        $pixelsB = $imgB->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_FLOAT);

        $imgA->destroy();
        $imgB->destroy();

        return $this->computeMeanSsim($pixelsA, $pixelsB, $width, $height);
    }
}
