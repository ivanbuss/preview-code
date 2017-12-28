<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerRouterData extends Model
{
	protected $table = 'customers_router_data';
	protected $primaryKey = 'id';
	protected $fillable = [
		'uuid', 'customer_id', 'plan_id', 'router_id', 'vpn_server_id', 'location_id', 'macaddress', 'activation_code', 'port', 'lan_ip', 'lan_netmask', 'dns_server1',
        'dns_server2', 'wifi_ssid', 'wifi_password', 'enabled', 'expired', 'queued', 'registered'
	];

	public function vpn_server() {
		return $this->belongsTo('App\Models\CustomerVPNData', 'vpn_server_id', 'id');
	}

	public function plan() {
		return $this->belongsTo('App\Models\RecurlyProducts', 'plan_id');
	}

}