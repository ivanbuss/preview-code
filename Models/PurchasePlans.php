<?php

namespace App\Models;

use App\Services\StoreRouterService;
use App\Services\StoreVPNService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Services\StoreProxyService;

class PurchasePlans extends Model
{
	protected $table = 'customer_purchase_plans';
	protected $primaryKey='id';
	protected $fillable = [
        'id', 'uuid','customer_id','plan_id', 'category_id', 'ip_address', 'coupon_code', 'purchase_date','expiration_date',
        'status', 'local_status', 'created_at','updated_at'
    ];

    protected $dates = ['purchase_date', 'expiration_date'];

    const ACTIVE = 'active';
    const CANCELED = 'canceled';
    const INACTIVE = 'inactive';
    const PROVISIONING = 'provisioning';
    const EXPIRED = 'expired';

    const ERROR = 'error';

	public function plan()
	{
		return $this->belongsTo('App\Models\RecurlyProducts', 'plan_id');
	}

    public function cancel_reason() {
      return $this->hasOne('App\Models\SubscriptionCancelReason', 'subscription_id', 'id');
    }

	public static function getUniqueUUID()
	{
		$random = hash('sha1', rand());
		$new = false;
		while($new !== true) {
			$public_ids = DB::table('customer_purchase_plans')->where('uuid', $random)->get();
			if (count($public_ids) > 0) {
				$random = hash('sha1', rand());
			}else{
				$new = true;
			}
		}
		return $random;
	}

    public function customer() {
        return $this->belongsTo('App\User', 'customer_id', 'id');
    }

	public function proxy_data()
	{
		return $this->hasMany('App\Models\CustomerProxyData','uuid', 'uuid');
	}

    public function vpn_data()
    {
        return $this->hasMany('App\Models\CustomerVPNData','uuid', 'uuid');
    }

    public function router_data() {
        return $this->hasMany('App\Models\CustomerRouterData','uuid', 'uuid');
    }

	public function isActive() {
		if ($this->proxy_data()->where('enabled', 0)->count()) return FALSE;
        if ($this->vpn_data()->where('enabled', 0)->count()) return FALSE;
        //if ($this->router_data()->where('enabled', 0)->count()) return FALSE;

        if ($this->expiration_date && Carbon::now() >= $this->expiration_date) {
            return FALSE;
        }

        return TRUE;
	}

    public function remainingTime() {
        $expiry_time = $this->expiration_date;
        if ($expiry_time) {
          $diff_time = $expiry_time->diff(Carbon::now());
          if ($this->isActive() && $diff_time->invert == 1) {
            $diff_time_string = '';
            if ($diff_time->y == 1) $diff_time_string .= $diff_time->y . ' year ';
            else if ($diff_time->y > 0) $diff_time_string .= $diff_time->y . ' years ';
            if ($diff_time->m == 1) $diff_time_string .= $diff_time->m . ' month ';
            else if ($diff_time->m > 0) $diff_time_string .= $diff_time->m . ' months ';
            if ($diff_time->d == 1) $diff_time_string .= $diff_time->d . ' day ';
            else if ($diff_time->d > 0) $diff_time_string .= $diff_time->d . ' days ';
            if ($diff_time->h == 1) $diff_time_string .= $diff_time->h . ' hour ';
            else if ($diff_time->h > 0) $diff_time_string .= $diff_time->h . ' hours ';
            if ($diff_time->i == 1) $diff_time_string .= $diff_time->i . ' minute ';
            else if ($diff_time->i > 0) $diff_time_string .= $diff_time->i . ' minutes ';
          } else {
            $diff_time_string = '0 minutes';
          }
        } else {
          $diff_time_string = 'Unlimited';
        }

        return $diff_time_string;
    }

    public function reactivate() {
        if ($this->proxy_data->count()) {
            $service = new StoreProxyService;
            $service->reactiveteProxyService($this, $this->plan, $this->customer);
        } else if ($this->vpn_data->count()) {
            $service = new StoreVPNService();
            $service->reactivateService($this, $this->plan, $this->customer);
        } else if ($this->router_data->count()) {
            $service = new StoreRouterService();
            $service->reactivateRouterService($this, $this->plan, $this->customer);
        }

        $this->expiration_date = Carbon::now()->addSeconds($this->plan->duration);
        $this->save();
        return TRUE;
    }

    public function extend($duration) {
        $this->expiration_date = $this->expiration_date->addHours($duration);
        $this->save();
        return TRUE;
    }
}
