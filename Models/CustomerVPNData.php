<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerVPNData extends Model
{
	protected $table = 'customers_vpn_data';
	protected $primaryKey = 'id';
	protected $fillable = [
		'id','uuid','customer_id','plan_id', 'server_id', 'server_region', 'server_city', 'server_ip_address',
		'authorized_ip_list', 'max_users', 'rotation_period','type','location','enabled', 'expired', 'date_added',
		'date_enabled','date_disabled','created_at','updated_at'
	];

	public function usersAssigned() {
		return $this->hasMany('App\Models\CustomerVPNServerUsers', 'vpn_data_id', 'id');
	}

	public function delete() {
		foreach($this->usersAssigned as $serversUser) {
			$serversUser->delete();
		}
		return parent::delete();
	}

	public function plan() {
		return $this->belongsTo('App\Models\RecurlyProducts', 'plan_id');
	}
}