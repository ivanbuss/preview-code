<?php

namespace App\Services;

use Illuminate\Support\Facades\Facade;

class ProxystarsRecurly_AccountFacade extends Facade{

    protected static function getFacadeAccessor() { return 'psrecurly_account'; }

}
