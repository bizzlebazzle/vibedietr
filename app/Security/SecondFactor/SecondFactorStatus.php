<?php

namespace App\Security\SecondFactor;

enum SecondFactorStatus: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Replayed = 'replayed';
    case Throttled = 'throttled';
    case Locked = 'locked';
    case Unavailable = 'unavailable';
}
