<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerVPNUsers extends Model
{
	protected $table = 'customers_vpn_users';
	protected $primaryKey = 'id';
	protected $fillable = [
		'customer_id', 'store_service_id', 'vpn_username', 'vpn_password', 'is_main', 'enabled'
	];

	public function serversAssigned() {
		return $this->hasMany('App\Models\CustomerVPNServerUsers', 'vpn_user_id', 'id');
	}

	public function delete() {
        foreach($this->serversAssigned as $serversUser) {
            $serversUser->delete();
        }
		return parent::delete();
	}

	public function isMain() {
		if ($this->is_main == 1) return TRUE;
			else return FALSE;
	}
}
