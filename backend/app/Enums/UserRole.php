<?php

namespace App\Enums;

enum UserRole: string
{
    case Driver = 'driver';
    case Operator = 'operator';
    case Admin = 'admin';
}
