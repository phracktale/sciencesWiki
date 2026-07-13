<?php

declare(strict_types=1);

namespace Analyses\Framework\Axis;

enum AxisAnswer: string
{
    case Yes = 'yes';
    case Partial = 'partial';
    case No = 'no';
    case Na = 'na';
    case Unclear = 'unclear';
}
