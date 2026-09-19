<?php

namespace App\Domain\Diary;

enum ConsumptionAction: string
{
    case Consume = 'consume';
    case Correct = 'correct';
    case Reverse = 'reverse';
    case Reconsume = 'reconsume';
}
