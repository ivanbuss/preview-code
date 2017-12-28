<?php

namespace App\Services;

use Recurly_Base;
use Recurly_Account;

class ProxystarsRecurly_Account extends Recurly_Account {

    public function __construct() {

    }

    public static function getBalance($accountCode) {
        $balance = Recurly_Base::_get(Recurly_Account::uriForAccount($accountCode) . '/balance');
        p($balance); exit;
    }
}
