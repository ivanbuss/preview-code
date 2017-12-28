<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProxyData extends Model
{
	protected $table = 'customers_proxy_data';
	protected $primaryKey='id';
	protected $fillable = ['id','uuid','customer_id','plan_id','ip_list', 'addresses', 'protocol', 'authorized_ip_list',
		'rotation_period','anytime_ports','type','location','region_changeable','enabled', 'expired', 'date_added',
		'date_enabled','date_disabled','created_at','updated_at'];

	public function plan() {
		return $this->belongsTo('App\Models\RecurlyProducts', 'plan_id');
	}
}
