<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class RecurlyProducts extends Model
{
	protected $table = 'recurly_products';
	protected $primaryKey='id';
	protected $fillable = [
		'plan_code','plan_name','plan_description','price','setup_fee','category_id','type', 'switch_type', 'plan_type','rotation_period',
		'allocation','location', 'city', 'region_changeable', 'plan_availability','plan_quantity','unit_of_measure',
		'billing_type','duration','anytime_ports','anytime_threads', 'router_model', 'vpn_users', 'parent_product',
        'day_requests','month_requests', 'created_at','updated_at'
	];

    public function subplans() {
        return $this->hasMany('App\Models\RecurlyProducts', 'parent_product', 'id');
    }

	public static function recurly_filter_search_query($category_id=null,$sort_by=null,$sort_type=null,$plan_type=null){
		if($category_id=="") {
			$cat_level = 0;
			$category_id = 11;
		}

		
		if ($plan_type=='')
			$plan_type = ['simple','complex'];
		elseif ($plan_type=='simple')
			$plan_type = ['simple'];
		elseif ($plan_type=='complex')
			$plan_type = ['complex'];


        $products = RecurlyProducts::whereIn('recurly_products.plan_type', $plan_type)
            ->where('recurly_products.category_id', $category_id);


		if($sort_by!==null && $sort_type!==null){
			$products  =	$products->orderBy($sort_by,$sort_type)->paginate(10);

		}else{
			$products  =	$products->paginate(10);
		}

		return $products;
	}

	public static function get_shared_productList()
	{
		$options = static::where('in_stock',1)->where('type','shared')->lists('plan_name','id')->all();
		$select = array('' => 'Select Shared Product');
		$output =$select+$options;
		return $output;
	}
	
	public static function get_shared_spin_productList()
	{
		$options = static::where('in_stock',1)->where('type','shared_spin')->lists('plan_name','id')->all();
		$select = array('' => 'Select Shared Spin Product');
		$output =$select+$options;
		return $output;
	}
	
	public static function get_shared_turbospin_productList()
	{
		$options = static::where('in_stock',1)->where('type','shared_turbospin')->lists('plan_name','id')->all();
		$select = array('' => 'Select Shared Product');
		$output =$select+$options;
		return $output;
	}
	
	public static function get_dedicated_productList()
	{
		$options = static::where('in_stock',1)->where('type','dedicated')->lists('plan_name','id')->all();
		$select = array('' => 'Select Dedicated Product');
		$output =$select+$options;
		return $output;
	}
	
	public static function get_dedicated_spin_productList()
	{
		$options = static::where('in_stock',1)->where('type','dedicated_spin')->lists('plan_name','id')->all();
		$select = array('' => 'Select Dedicated Spin Product');
		$output =$select+$options;
		return $output;
	}
	
	public static function get_dedicated_turbospin_productList()
	{
		$options = static::where('in_stock',1)->where('type','dedicated_turbospin')->lists('plan_name','id')->all();
		$select = array('' => 'Select Dedicated Turbospin Product');
		$output =$select+$options;
		return $output;
	}

	public function ProductsByCategory($category_id){
		return $this->where('category_id', $category_id);
	}

	public function users()
	{
		return $this->belongsToMany('App\User','customer_purchase_plans','plan_id','customer_id');
	}
	public function proxyDatByUsers()
	{
		return $this->belongsToMany('App\User','customers_proxy_data','plan_id','customer_id');
	}
	public function bundlePlans()
	{
		return $this->belongsToMany('App\Models\RecurlyProducts','bundle_plans','bundle_id','plan_id')->withTimestamps();
	}

	public function isUnlimited() {
		if ($this->duration == -1) return TRUE;
			else return FALSE;
	}

	public function getDurationSeconds() {
        switch ($this->duration) {
            case '1_days':
                $time = strtotime('1 day',0);
                break;
            case '3_days':
                $time = strtotime('3 day',0);
                break;
            case '7_days':
                $time = strtotime('7 day',0);
                break;
            case '1_months':
                $time = strtotime('1 month',0);
                break;
            case '3_months':
                $time = strtotime('3 month',0);
                break;
            case '6_months':
                $time = strtotime('6 month',0);
                break;
            case '12_months':
                $time = strtotime('12 month',0);
                break;
			case '24_months':
				$time = strtotime('24 month',0);
				break;
            case '36_months':
                $time = strtotime('36 month',0);
                break;
            default:
                $time = $this->duration;
                break;
        }
        return $time;
	}

	public function getDurationText($short = FALSE) {
		if ($short) {
			switch ($this->duration) {
				case '1_days':
					$duration = 'day';
					break;
				case '3_days':
					$duration = '3 days';
					break;
				case '7_days':
					$duration = '7 days';
					break;
				case '1_months':
					$duration = 'month';
					break;
				case '3_months':
					$duration = '3 months';
					break;
				case '6_months':
					$duration = '6 months';
					break;
				case '12_months':
					$duration = '12 months';
					break;
				case '36_months':
					$duration = '36 months';
					break;
				case -1:
					$duration = 'Unlimited';
					break;
				case 3600:
					$duration = '1 Hour';
					break;
				case 10800:
					$duration = '3 Hours';
					break;
				case 28800:
					$duration = '8 Hours';
					break;
				case 86400:
					$duration = '1 Day';
					break;
				case 259200:
					$duration = '3 Days';
					break;
				default:
					$duration = $this->duration;
					break;
			}
		} else {
			switch ($this->duration) {
				case '1_days':
					$duration = '1 Day';
					break;
				case '3_days':
					$duration = '3 Days';
					break;
				case '7_days':
					$duration = '7 Days';
					break;
				case '1_months':
					$duration = '1 Month';
					break;
				case '3_months':
					$duration = '3 Months';
					break;
				case '6_months':
					$duration = '6 Months';
					break;
				case '12_months':
					$duration = '12 Months';
					break;
				case '36_months':
					$duration = '36 Months';
					break;
				case -1:
					$duration = 'Unlimited';
					break;
				case 3600:
					$duration = '1 Hour';
					break;
				case 10800:
					$duration = '3 Hours';
					break;
				case 28800:
					$duration = '8 Hours';
					break;
				case 86400:
					$duration = '1 Day';
					break;
				case 259200:
					$duration = '3 Days';
					break;
				default:
					$duration = $this->duration;
					break;
			}
		}

		return $duration;
	}

	public function hasActivePlans() {
		$p_plans = PurchasePlans::where('plan_id',$this->id)->get();
		$all_expired = 1;

		$time = $this->getDurationSeconds();
		foreach ($p_plans as $p_plan) {
			$expiry_time = $p_plan->created_at->addSeconds($time);
			if (Carbon::now() < $expiry_time) {
				$all_expired = 0;
				break;
			}
		}
		if ($all_expired) return FALSE;
			else return TRUE;
	}

	public function approveChanges(array $data) {
        if ($this->hasActivePlans()) {
            if ($this->plan_type != $data['recurly_create_plan_type']
                || $this->plan_code != $data['plan_code']
                || $this->type != $data['type']
                || $this->category_id != $data['recurly_category_id']
                || $this->billing_type != $data['billing_type']) {
                return FALSE;
            }
        }
        return TRUE;
	}

	public function region() {
		return $this->belongsTo('App\Models\Regions', 'location', 'rid');
	}

    public function category() {
        return $this->belongsTo('App\Models\RecurlyCategories', 'category_id', 'id');
    }

    public function categoryTree() {
        $list = '';
        if ($this->category) {
			if (Cache::has('category_tree_' . $this->category->id)) {
				$list = Cache::get('category_tree_' . $this->category->id);
			} else {
				$list = $this->category->name . $list;
				if ($this->category->parent) {
					$list = $this->category->parent->name . ' > ' . $list;
					if ($this->category->parent->parent) {
						$list = $this->category->parent->parent->name . ' > ' . $list;
						if ($this->category->parent->parent->parent) {
							$list = $this->category->parent->parent->parent->name . ' > ' . $list;
							if ($this->category->parent->parent->parent->parent) $list = $this->category->parent->parent->parent->parent->name . ' > ' . $list;
						}
					}
				}
				Cache::put('category_tree_' . $this->category->id, $list, 1440);
			}
		}

        return $list;
    }

	public function getDurationInDays() {
		$durations = [
			'1_days'         =>  1,
			'3_days'         =>  3,
			'7_days'         =>  7,
		];
		if (isset($durations[$this->duration])) {
			return $durations[$this->duration];
		} else return 0;
	}

	public function getDurationInMonths() {
		$durations = [
			'1_months'       =>  1,
			'3_months'       =>  3,
			'6_months'       =>  6,
			'12_months'      =>  12,
			'24_months'      =>  24,
			'36_months'       =>  36,
		];
		if (isset($durations[$this->duration])) {
			return $durations[$this->duration];
		} else return 0;
	}

	public function getPriceByMonth() {
		$duration_months = $this->getDurationInMonths();

		if ($duration_months > 0) {
			return $this->price / $duration_months;
		} else return $this->price;
	}

	public function getPlanType() {
		if ($this->plan_type == 'complex') return 'proxy_complex';
			else if ($this->plan_type == 'simple' && ($this->type == 'shared' || $this->type == 'shared_spin' || $this->type == 'shared_turbospin')) return 'proxy_shared';
			else if ($this->plan_type == 'simple' && $this->type == 'dedicated') return 'proxy_dedicated';
			else if ($this->plan_type == 'dedicated' && $this->type == 'dedicated') return 'proxy_dedicated_turbo';
			else if ($this->plan_type == 'vpn_dedicated') return 'vpn_dedicated';
			else if ($this->plan_type == 'router') return 'router';
			else if ($this->plan_type == 'package') return 'package';
	}

	public function isSimpleVPN() {
		if ($this->getPlanType() == 'vpn_dedicated' && $this->getCityId() == 5 || $this->getCityId() == 7) return TRUE;
			else return FALSE;
	}

	public function isTrialSimpleVPN() {
		if ($this->getPlanType() == 'vpn_dedicated' && $this->getCityId() == 5) return TRUE;
			else return FALSE;
	}

	public function getCityId() {
		return $this->city;
	}

	public function isRouterPackage() {
		$bundle_plans = $this->bundlePlans()->get();
		foreach($bundle_plans as $plan) {
			if ($plan->getPlanType() == 'router') return TRUE;
		}
		return FALSE;
	}

}
