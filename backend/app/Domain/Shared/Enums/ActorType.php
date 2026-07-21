<?php

namespace App\Domain\Shared\Enums;

enum ActorType: string
{
    case System = 'system';
    case Admin = 'admin';
    case Customer = 'customer';
    case Service = 'service';
}
