<?php

namespace App\Domain\Imports\Enums;

enum ImportRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
