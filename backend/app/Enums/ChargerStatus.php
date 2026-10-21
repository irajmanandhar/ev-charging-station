<?php

namespace App\Enums;

/** Operator-controlled availability of a charger. */
enum ChargerStatus: string
{
    case Available = 'available';
    case Offline = 'offline';
    case Maintenance = 'maintenance';
}
