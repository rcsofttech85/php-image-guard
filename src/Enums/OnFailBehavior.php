<?php

declare(strict_types=1);

namespace RcSoftTech\ImageGuard\Enums;

enum OnFailBehavior
{
    /** Keep retrying up to maxQuality, then accept best result. */
    case RECOMPRESS;

    /** Log a warning and return a result with passed=false. */
    case WARN;

    /** Throw ImageQualityException with the full pipeline report. */
    case ABORT;
}
