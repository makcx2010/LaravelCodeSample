<?php

namespace App\Enums;

enum MatchStatus: string
{
    case pending = 'pending';
    case active = 'active';
    case finished = 'finished';
    case failed = 'failed';
}
