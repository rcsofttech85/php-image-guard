<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Ssim;

interface SsimCalculatorInterface
{
    /**
     * Compare two images and return their SSIM score.
     *
     * @param string $pathA absolute path to the reference (original) image
     * @param string $pathB absolute path to the comparison (compressed) image
     *
     * @return float SSIM score in [0.0, 1.0], 1.0 meaning identical.
     */
    public function compare(string $pathA, string $pathB): float;
}
