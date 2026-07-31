<?php

namespace App\Domain\Measurements;

enum MeasurementDimension: string
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';
    case Custom = 'custom';
}
