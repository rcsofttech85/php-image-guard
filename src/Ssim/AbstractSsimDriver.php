<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Ssim;

use function count;

/**
 * Shared SSIM windowing and formula logic.
 *
 * Based on the 2004 IEEE paper by Wang, Bovik, Sheikh, and Simoncelli.
 * Formula: SSIM(x,y) = (2μxμy + C1)(2σxy + C2) / ((μx² + μy² + C1)(σx² + σy² + C2))
 *
 * Subclasses provide C1/C2 stabilization constants and pixel extraction
 * via their driver-specific image loading logic.
 *
 * Window size: 8×8, step: 4px.
 */
abstract class AbstractSsimDriver implements SsimCalculatorInterface
{
    private const int WINDOW_SIZE = 8;

    private const int STEP = 4;

    abstract protected function c1(): float;

    abstract protected function c2(): float;

    /**
     * Slide an 8×8 window across the image (step = 4), compute SSIM per window,
     * and return the arithmetic mean of all window scores.
     *
     * @param array<int, float> $grayA
     * @param array<int, float> $grayB
     */
    protected function computeMeanSsim(array $grayA, array $grayB, int $width, int $height): float
    {
        $windowCount = 0;
        $ssimSum = 0.0;

        $maxY = $height - self::WINDOW_SIZE;
        $maxX = $width - self::WINDOW_SIZE;

        for ($y = 0; $y <= $maxY; $y += self::STEP) {
            for ($x = 0; $x <= $maxX; $x += self::STEP) {
                [$winA, $winB] = $this->extractWindows($grayA, $grayB, $x, $y, $width);
                $ssimSum += $this->ssimForWindow($winA, $winB);
                ++$windowCount;
            }
        }

        if ($windowCount === 0) {
            $ssimSum = $this->ssimForWindow(array_values($grayA), array_values($grayB));
            $windowCount = 1;
        }

        return round($ssimSum / $windowCount, 6);
    }

    /**
     * Extract parallel pixel value arrays for a single 8×8 window.
     *
     * @param array<int, float> $grayA
     * @param array<int, float> $grayB
     *
     * @return array{0: list<float>, 1: list<float>}
     */
    private function extractWindows(
        array $grayA,
        array $grayB,
        int $originX,
        int $originY,
        int $width,
    ): array {
        $winA = [];
        $winB = [];

        for ($wy = 0; $wy < self::WINDOW_SIZE; ++$wy) {
            for ($wx = 0; $wx < self::WINDOW_SIZE; ++$wx) {
                $idx = ($originY + $wy) * $width + ($originX + $wx);
                $winA[] = $grayA[$idx];
                $winB[] = $grayB[$idx];
            }
        }

        return [$winA, $winB];
    }

    /**
     * Apply the SSIM formula for a matched pair of pixel value arrays.
     *
     * @param list<float> $winA
     * @param list<float> $winB
     */
    private function ssimForWindow(array $winA, array $winB): float
    {
        $n = count($winA);

        if ($n === 0) {
            return 1.0;
        }

        $muA = array_sum($winA) / $n;
        $muB = array_sum($winB) / $n;

        $varA = 0.0;
        $varB = 0.0;
        $cov = 0.0;

        for ($i = 0; $i < $n; ++$i) {
            $da = $winA[$i] - $muA;
            $db = $winB[$i] - $muB;
            $varA += $da * $da;
            $varB += $db * $db;
            $cov += $da * $db;
        }

        $varA /= $n;
        $varB /= $n;
        $cov /= $n;

        $c1 = $this->c1();
        $c2 = $this->c2();

        $numerator = (2.0 * $muA * $muB + $c1) * (2.0 * $cov + $c2);
        $denominator = ($muA * $muA + $muB * $muB + $c1) * ($varA + $varB + $c2);

        if ($denominator === 0.0) {
            return 1.0;
        }

        return $numerator / $denominator;
    }
}
