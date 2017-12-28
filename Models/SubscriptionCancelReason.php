<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionCancelReason extends Model
{
	protected $table = 'subscription_cancel_reasons';
	protected $primaryKey = 'id';
	protected $fillable = [
        'customer_id', 'subscription_id', 'cancel_reason', 'cancel_comment'
    ];

	public $reasons = [
		'no_needed' => 'No longer needed',
		'another_provider' => 'Switching to another provider',
		'services_not_work' => 'Services do not work as expected',
		'customer_service' => 'Customer Service is not as expected',
		'cheaper_provider' => 'Found a cheaper provider',
		'price' => 'Prices are too High',
	];

	public function subscription() {
		return $this->belongsTo('App\Models\PurchasePlans', 'subscription_id', 'id');
	}

	public function customer() {
		return $this->belongsTo('App\User', 'customer_id', 'id');
	}

	public function getReasons() {
		$cancel_reasons = json_decode($this->cancel_reason);
		$reasons = [];
		if (is_array($cancel_reasons)) {
			foreach($cancel_reasons as $cancel_reason) {
				if (isset($this->reasons[$cancel_reason])) $reasons[$cancel_reason] = $this->reasons[$cancel_reason];
			}
		}
		return $reasons;
	}

	public function ticket() {
		$data = array(
			'name' => 'Cancellation',
			'email' => 'support@proxystars.com',
			'subject' => 'Cancellation',
			'description' => '<p>Service has been cancelled</p>
<p>Service ID: '.$this->subscription->uuid.'</p><p>Customer Email: '.$this->customer->email.'</p>',
			'status' => 2,
			'priority' => 4,
		);

		$jsondata= json_encode($data);
		$url = "https://proxystars.freshdesk.com/api/v2/tickets";
		$sent = curlWrap("freshdesk",$url, $jsondata, "POST");
	}

}
