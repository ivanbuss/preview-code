<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingData extends Model
{
	protected $table = 'shipping_data';
	protected $primaryKey = 'id';
	protected $fillable = [
        'uuid', 'customer_id', 'first_name', 'last_name', 'address', 'city', 'country', 'postal_code', 'state', 'phone',
		'activate', 'shipper', 'shipper_updated_at', 'tracking_number', 'status', 'status_updated_at'
    ];

	/**
	 * The attributes that should be mutated to dates.
	 *
	 * @var array
	 */
	protected $dates = [
		'shipper_updated_at',
        'status_updated_at',
	];

}
