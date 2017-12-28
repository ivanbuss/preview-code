<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerVPNServerUsers extends Model
{
	protected $table = 'customers_vpn_server_users';
	protected $primaryKey = 'id';
	protected $fillable = [
		'vpn_data_id', 'vpn_user_id'
	];

	public function vpnuser() {
		return $this->belongsTo('App\Models\CustomerVPNUsers', 'vpn_user_id', 'id');
	}

    public function vpnserver() {
        return $this->belongsTo('App\Models\CustomerVPNData', 'vpn_data_id', 'id');
    }

}
