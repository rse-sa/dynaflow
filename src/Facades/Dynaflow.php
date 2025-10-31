<?php

namespace RSE\DynaFlow\Facades;

use Illuminate\Support\Facades\Facade;

class Dynaflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'dynaflow.manager';
    }
}
