# php-image-guard

[![PHP 8.4](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHPStan: Max](https://img.shields.io/badge/PHPStan-max-brightgreen.svg)](phpstan.neon)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/54b032919e964501a4d681f561425a62)](https://app.codacy.com/gh/rcsofttech85/php-image-guard/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_grade)
[![Codacy Badge](https://app.codacy.com/project/badge/Coverage/54b032919e964501a4d681f561425a62)](https://app.codacy.com/gh/rcsofttech85/php-image-guard/dashboard?utm_source=gh&utm_medium=referral&utm_content=&utm_campaign=Badge_coverage)

> SSIM-based image quality verification with auto-retry compression loop
> for PHP 8.4+

This package does **ONE job**: guard image quality.
It does not compress, resize, or upload images.
It verifies that a compression result meets your quality threshold —
and retries if not.

---

## Requirements

- PHP 8.4+
- `ext-gd` (required)
- `ext-imagick` (optional — enables higher-precision SSIM via float pixel data)

---

## Installation

```bash
composer require rcsofttech/php-image-guard
```

## Quick Start

### Minimal — check one image

```php
use RcSoftTech\ImageGuard\ImageGuard;

$gdImage = imagecreatefromjpeg('original.jpg');

$result = ImageGuard::check(
    'original.jpg',
    function (int $quality) use ($gdImage): string {
        $out = "/tmp/compressed_q{$quality}.jpg";
        imagejpeg($gdImage, $out, $quality);
        return $out;
    }
);

echo $result->summary();
// "Passed (SSIM: 0.961, Quality: 75, Saved: 68%, 143ms)"
```

### Standalone SSIM score (no retry)

```php
$score = ImageGuard::compare('original.jpg', 'compressed.jpg');
// float e.g. 0.961423
```

---

## Full Fluent API

```php
use RcSoftTech\ImageGuard\Enums\OnFailBehavior;
use RcSoftTech\ImageGuard\ImageGuard;

$result = ImageGuard::original('original.jpg')
    ->compressWith($compressor)
    ->threshold(0.92)            // or: 'strict' | 'balanced' | 'loose'
    ->startAt(75)                // initial quality integer
    ->maxQuality(95)             // ceiling for retry loop
    ->step(5)                    // quality bump per retry
    ->onFail(OnFailBehavior::RECOMPRESS)
    ->run(ImageGuard::resolveCalculator());

if ($result->failed()) {
    logger()->warning('Image below threshold', $result->toArray());
}
```

### Quality Presets

| String       | SSIM Threshold |
|--------------|----------------|
| `'strict'`   | 0.97           |
| `'balanced'` | 0.92           |
| `'loose'`    | 0.85           |

### Failure Behaviors

| `OnFailBehavior` | Action                                              |
|------------------|-----------------------------------------------------|
| `RECOMPRESS`     | Retry up to `maxQuality`, then accept best result   |
| `WARN`           | Return `passed=false` + add `$result->warnings`     |
| `ABORT`          | Throw exception holding the full `PipelineResult`   |

---

## Batch API

```php
$report = ImageGuard::batch(glob('uploads/*.jpg'))
    ->compressWith($compressor)
    ->threshold('balanced')
    ->onFail(OnFailBehavior::WARN)
    ->run(ImageGuard::resolveCalculator());

echo $report->totalSaved();  // "38.1 MB saved across 147 images"
echo $report->failRate();    // "3 of 147 failed (2.0%)"
echo $report->averageSsim(); // 0.941200

file_put_contents('audit.json', $report->toJson());
file_put_contents('audit.csv', $report->toCsv());
```

---

## Compressor Examples

The compressor callable receives a `quality` integer and **must return
the path to the compressed file**. This decouples `php-image-guard`
from any specific tool.

### Plain GD

```php
$compressor = function (int $quality) use ($gdImage, $tmpDir): string {
    $output = "{$tmpDir}/compressed_q{$quality}.jpg";
    imagejpeg($gdImage, $output, $quality);
    return $output;
};
```

### With `spatie/image-optimizer`

```php
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;

$compressor = function (int $quality) use ($sourcePath, $tmpDir): string {
    $output = "{$tmpDir}/compressed_q{$quality}.jpg";
    copy($sourcePath, $output);
    (new OptimizerChain)->addOptimizer(
        new Jpegoptim(['--max=' . $quality])
    )->optimize($output);
    return $output;
};
```

### With `intervention/image`

```php
$compressor = function (int $quality) use ($image, $tmpDir): string {
    $output = "{$tmpDir}/compressed_q{$quality}.webp";
    $image->toWebp($quality)->save($output);
    return $output;
};
```

---

## PipelineResult Shape

```php
$result->passed          // bool
$result->ssimScore       // float e.g. 0.961423
$result->threshold       // float e.g. 0.92
$result->qualityUsed     // int — final quality used
$result->attempts        // int — number of retry attempts
$result->originalSize    // int — bytes
$result->compressedSize  // int — bytes
$result->savingsPercent  // float e.g. 68.4
$result->formatInput     // string e.g. 'jpeg'
$result->formatOutput    // string e.g. 'webp'
$result->durationMs      // int — total pipeline time
$result->outputPath      // string — path to accepted output
$result->warnings        // string[] — any warnings

$result->summary()       // "Passed (SSIM: 0.961, Quality: 75, Saved: 68%, 143ms)"
$result->savings()       // "68%"
$result->passed()        // bool
$result->failed()        // bool
$result->toArray()       // array<string, mixed>
$result->toJson()        // JSON string
```

## SSIM Accuracy

| Scenario         | Expected        |
|------------------|-----------------|
| Identical images | SSIM = 1.000000 |
| White vs black   | SSIM < 0.05     |
| JPEG quality 95  | SSIM > 0.98     |
| JPEG quality 10  | SSIM < 0.80     |
| PNG vs 50% WebP  | 0.85–0.98       |

---

## License

MIT — see [LICENSE](LICENSE).
