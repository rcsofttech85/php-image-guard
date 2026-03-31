<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Exceptions;

use RcSoftTech\ImageGuard\Report\PipelineResult;
use RuntimeException;
use Throwable;

final class ImageQualityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly PipelineResult $result,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
