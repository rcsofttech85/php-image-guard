<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Ssim;

use Override;

use function extension_loaded;

/**
 * Public façade for Imagick-based SSIM.
 *
 * Automatically falls back to GdSsimDriver when ext-imagick is not loaded,
 * so callers can always safely instantiate this class.
 */
final class ImagickSsimDriver implements SsimCalculatorInterface
{
    private readonly SsimCalculatorInterface $delegate;

    public function __construct()
    {
        $this->delegate = extension_loaded('imagick')
            ? new ImagickCore()
            : new GdSsimDriver();
    }

    #[Override]
    public function compare(string $pathA, string $pathB): float
    {
        return $this->delegate->compare($pathA, $pathB);
    }
}
