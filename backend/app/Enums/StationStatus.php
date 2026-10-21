<?php

namespace App\Enums;

enum StationStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
