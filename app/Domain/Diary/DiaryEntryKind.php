<?php

namespace App\Domain\Diary;

enum DiaryEntryKind: string
{
    case Recipe = 'recipe';
    case Catalogue = 'catalogue';
    case OneOff = 'one_off';
}
