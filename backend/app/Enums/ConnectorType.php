<?php

namespace App\Enums;

enum ConnectorType: string
{
    case CCS2 = 'CCS2';
    case CHAdeMO = 'CHAdeMO';
    case Type2 = 'Type2';
    case GBT = 'GBT';
}
