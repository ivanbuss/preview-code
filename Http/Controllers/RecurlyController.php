<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateBundleProductRequest;
use App\Http\Requests\CreateCategoryRequest;
use App\Http\Requests\CreateRecurlyProductRequest;
use App\Models\Bundles;
use App\Models\City;
use App\Models\CustomerProxyData;
use App\Models\CustomerRouterData;
use App\Models\CustomerVPNData;
use App\Models\CustomerVPNServerUsers;
use App\Models\CustomerVPNUsers;
use App\Models\RecurlyCategories;
use App\Models\RecurlyProducts;
use App\Models\Regions;
use App\Models\SettingsModel;
use App\Services\RecurlyService;
use App\Services\Settings;
use App\Services\StoreProxyService;
use App\Services\StoreRouterService;
use App\Services\StoreVPNService;
use App\User;
use App\Models\PurchasePlans;
use Carbon\Carbon;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Mockery\CountValidator\Exception;
use Recurly_Client;
use Recurly_NotFoundError;
use Recurly_Plan;
use Recurly_PlanList;
use App\Helpers;

class RecurlyController extends Controller
{

    protected $recurlyService;
	protected $settings;
    protected $vpnService;
	protected $routerService;
    protected $proxyService;

    function __construct(RecurlyService $recurlyService, Settings $settings, StoreVPNService $VPNService, StoreRouterService $routerService, StoreProxyService $proxyService) {
        $this->recurlyService = $recurlyService;
		$this->settings = $settings;
        $this->vpnService = $VPNService;
		$this->routerService = $routerService;
        $this->proxyService = $proxyService;
    }


	public function recurlyProducts(Request $request) {
		$parent_recurly_categories  = RecurlyCategories::getTopParentList();
		$sorting_options = recurly_sorting_options();

		if (Auth::user()->role == "admin") {
            return redirect()->action('Admin\ProductsController@recurlyProducts');
		} else {
            $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
            $account = $this->recurlyService->getAccount(Auth::user());

			$local_plans = RecurlyProducts::where('billing_type',"duration")
                ->where('plan_type','simple')
                ->where('plan_availability',"in_stock")
                ->orWhere('plan_type','complex')
                ->where('plan_availability',"in_stock")
                ->paginate(10);

			return view('recurly.recurly_product')
				->with('billing_info',$billing_info)
				->with('local_plans',$local_plans)
				->with('parent_recurly_categories',$parent_recurly_categories)
				->with('account',$account)
				->with('sorting_options',$sorting_options);
		}
	}

	public function recurlyProductsByFilter(){
		$cat_id = "";
        $parent_category_id = Input::get('filter_parent_category_id');

		if ($parent_category_id !== null && $parent_category_id!==""){
			$cat_id = $parent_category_id;
		}
        for($i=1; $i<=5; $i++) {
            $child_category_ids[] = Input::get('filter_child_category_id_'.$i);
        }

        $child_category_id = '';
        foreach($child_category_ids as $child_cat_id) {
            if (!empty($child_cat_id)) $child_category_id = $child_cat_id;
        }

		if ($child_category_id !== null && $child_category_id !== ""){
			$cat_id = $child_category_id;
		}
		$sort_by = Input::get('sort_by');
		$sort_type = Input::get('sort_type');
		$search_by_plan_type = Input::get('search_by_plan_type');

		$parent_recurly_categories  = RecurlyCategories::getTopParentList();
 		$recurly_categories = RecurlyCategories::getList();
		$sorting_options = recurly_sorting_options();

		

		$local_plans = RecurlyProducts::recurly_filter_search_query($cat_id, $sort_by, $sort_type, $search_by_plan_type);
		
		return view('recurly.admin.recurly_product')->with('local_plans',$local_plans)
			                                            ->with('recurly_categories',$recurly_categories)
														->with('parent_recurly_categories',$parent_recurly_categories)
														->with('sorting_options',$sorting_options)
														->withInput(Input::except('password'));

	}

	public function getRecurlyCategories(){
		$cat_level = recurly_category_level();
		$category_tree = recurly_category_tree();
		return view('recurly.admin.recurly_categories')
			->with('cat_level', $cat_level)
			->with('category_tree', $category_tree);

	}

	public function addRecurlyCategoryForm(Request $request){
		if ($request->ajax()) {
			return response()->json(array(
				'add_category_form' => (String)view('recurly.admin.add_recurly_category_form')
			));
		}
	}


	public function addRecurlyCategory(CreateCategoryRequest $request){
		$cat_name       = Input::get('name');
        $cat_description = Input::get('description');
		$level_name = Input::get('level_name');
        $cat_weight       = Input::get('weight');
        $is_trial = Input::get('trial');
		$is_default = Input::get('is_default');
		$no_subcats = Input::get('no_subcats');
		$parent_cat_id  = Input::get('parent_category_id');
		if ($parent_cat_id == null || $parent_cat_id == ""){
			$parent_cat_id = 0;
			$child_category_level_id = 0;
		}
		$parentCategoryDetail = RecurlyCategories::where('id',$parent_cat_id)->first();

		if ($parentCategoryDetail) {
            $productsOfParentCategory = RecurlyProducts::where('category_id',$parentCategoryDetail->id)->lists('id');
			if (!$productsOfParentCategory->isEmpty()) {
				return Redirect::back()->with('error', 'can not add category because parent category belongs to many products.');
			}
	        $parent_category_level_id = $parentCategoryDetail->cat_level_id;
			$child_category_level_id = $parent_category_level_id +1;
        }
		$createCategory = RecurlyCategories::create([
			'name' =>  htmlspecialchars(trim($cat_name)),
            'description' => htmlspecialchars(trim($cat_description)),
			'level_name' => htmlspecialchars(trim($level_name)),
			'cat_level_id' => $child_category_level_id,
			'parent_category_id' => $parent_cat_id,
            'weight' => $cat_weight,
            'trial' => $is_trial ? 1 : 0,
			'is_default' => $is_default ? 1 : 0,
			'no_subcats' => $no_subcats ? 1 : 0
		]);

		if ($createCategory) {
			return Redirect::back()->with('success', 'category successfully created.');
		} else {
			return Redirect::back()->withErrors([
				'error' => 'Failed to create category! ',
			]);
		}
	}

	public function updateRecurlyCategoryForm(Request $request) {
		if ($request->ajax()) {
			$cat_id = Input::get('category_id');
			$category = RecurlyCategories::where('id',$cat_id)->first();

			if ($category) {
				$plans = RecurlyProducts::where('category_id', $category->id)->lists('plan_code', 'id');
				return response()->json(array(
					'update_category_form' => (String)view('recurly.admin.update_recurly_category_form')
						->with('category',$category)->with('products', $plans),
				));
			}
			else{
				return response()->json(array(
					'update_category_form' => 'No category record found! '
				));
			}
		}
	}


	public function updateRecurlyCategory(Request $request, RecurlyCategories $category){
		$cat_name = Input::get('name');
        $cat_description = Input::get('description');
		$level_name = Input::get('level_name');
        $cat_weight = Input::get('weight');
        $is_trial = Input::get('trial');
		$is_default = Input::get('is_default');
		$no_Subcats = Input::get('no_subcats');
        $recommended_plan = Input::get('recommended_plan');
        $validator = $this->categoryValidator($request->all(), $category);
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $category->name = htmlspecialchars(trim($cat_name));
        $category->description = htmlspecialchars(trim($cat_description));
		$category->level_name = htmlspecialchars(trim($level_name));
        $category->weight = $cat_weight;
        $category->trial = $is_trial ? 1 : 0;
        $category->recommended_plan = $recommended_plan;
		$category->is_default = $is_default ? 1 : 0;
		$category->no_subcats = $no_Subcats ? 1 : 0;

		if ($category->save()) {
			return Redirect::back()->with('success', 'category name successfully updated.');
		} else {
			return Redirect::back()->withErrors([
				'error' => 'Failed to update category! ',
			]);
		}

	}

	public function deleteCategory(Request $request,$category_id){
		$category = RecurlyCategories::where('id',$category_id)->first();
		if ($category) {
			$childCategories = RecurlyCategories::where('parent_category_id',$category_id)->lists('id');
			$productsOfCategory = RecurlyProducts::where('category_id',$category_id)->lists('id');

            if (!$childCategories->isEmpty()) {
	            return Redirect::back()->withErrors([
		            'error' => 'You can not delete parent category. Delete child first! ',
	            ]);
            } elseif (!$productsOfCategory->isEmpty()){
				return Redirect::back()->withErrors([
					'error' => 'You can not delete this category because this belongs to many products!',
				]);
			} else{
				$id = $category->id;
				$category->delete();
				$setting = SettingsModel::where('name', 'discounts_'.$id)->first();
				if ($setting) $setting->delete();
				return Redirect::back()->with('success', 'category successfully deleted.');
			}

		} else{
			return Redirect::back()->withErrors([
				'error' => 'Failed to delete category! ',
			]);
		}


	}

	public function getChildsCategoriesByParent(Request $request) {
		if ($request->ajax()) {
			$parent_category  = Input::get('parent_category');
            $number = Input::get('number');
			$childCategories  = RecurlyCategories::where('parent_category_id',$parent_category)->lists('name','id')->all();
            if ($childCategories) {
                $select           = array('' => 'Select Product Sub category');
			    $childCategories  = $select + $childCategories;
                return response()->json(array(
                    'child_categories' => (String)view('recurly.admin.partial_child_categories')
                        ->with('childCategories',$childCategories)
                        ->with('number', $number),
                ));
            } else {
                return response()->json(['child_categories'=>null]);
            }
		}
	}

    public function showSimpleCustomerProductDetails($uuid, RecurlyProducts $plan, $package = false) {
		$user_id = Auth::user()->id;
		$purchaseplan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $user_balance = $this->recurlyService->getAccountBalance(Auth::user());
        $amount_due = $this->recurlyService->getPostDueAmount(Auth::user());
        $credit_used = $amount_due + $user_balance;
        if ($credit_used < 0) $credit_used = 0;
        if ($credit_used > $amount_due) $credit_used = $amount_due;
        $countries = \CountryState::getCountries();

		$rotation_period = recurly_rotation_period2();

		$regions = Regions::getActivated();

        $active = $purchaseplan->isActive(); $diff_time_string = '';
        if ($active) $diff_time_string = $purchaseplan->remainingTime();
        $disabled = $purchaseplan->status == PurchasePlans::EXPIRED ? TRUE : FALSE;
		$expiration_date = $purchaseplan->expiration_date;

		$billing_info = $this->recurlyService->getBillingInfo(Auth::user());
        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
        $billing_descriptor = $this->settings->get('billing_descriptor');
		$account = $this->recurlyService->getAccount(Auth::user());
		$expire_date = $purchaseplan->expiration_date;

		$renew = FALSE;
		if ($plan->billing_type != 'trial') {
			$subscription = $this->recurlyService->getSubscription($purchaseplan->uuid);
			if ($subscription) {
				$invoice = $subscription->invoice->get();
				if ($invoice && $invoice->state != 'collected') {
					$renew = TRUE;
				}
			}
		}

		if ($purchaseplan->local_status != PurchasePlans::ERROR && $purchaseplan->local_status != PurchasePlans::PROVISIONING && ($purchaseplan->status == PurchasePlans::ACTIVE || $purchaseplan->status == PurchasePlans::CANCELED || $purchaseplan->status == PurchasePlans::EXPIRED)) {
			$userProxyData = Auth::user()->proxyData()->where('uuid', $uuid)->first();
			$serilize_ip_list = $userProxyData->pivot ? $userProxyData->pivot->ip_list : null;
			if ($serilize_ip_list) {
				$ip_list_array = unserialize($serilize_ip_list);
			}
			$serilize_addresses_list = $userProxyData->pivot ? $userProxyData->pivot->addresses : null;
			$addresses_list = [];
			if ($serilize_addresses_list) {
				$addresses = unserialize($serilize_addresses_list);
				foreach($addresses as $id=>$addresses_item) {
					$addresses_list[$addresses_item['port']] = $addresses_item['address'];
				}
			}
			$serilize_authorized_ip_list = $userProxyData->pivot ? $userProxyData->pivot->authorized_ip_list : null;
			$authorized_ip_array = [];
			if ($serilize_authorized_ip_list) {
				$authorized_ip_array = unserialize($serilize_authorized_ip_list);
			}

			$proxy_rotation = $userProxyData->pivot ? $userProxyData->pivot->rotation_period : null;
			$proxy_location = $userProxyData->pivot ? $userProxyData->pivot->location : null;
		} elseif ($purchaseplan->status == PurchasePlans::PROVISIONING || $purchaseplan->local_status == PurchasePlans::PROVISIONING || $purchaseplan->local_status == PurchasePlans::ERROR) {
			$proxy_rotation = $plan->rotation_period;
			return view('recurly.product_details_provisioning')->with('plan', $plan)
				->with('rotation_period', $rotation_period)
				->with('regions', $regions)
				->with('proxy_rotation', $proxy_rotation)
				->with('expiry_date', $expiration_date)
				->with('billing_info', $billing_info)
				->with('account', $account)
				->with('expire_date', $expire_date)
				->with('purchase_plan', $purchaseplan)
				->with('diff_time_string', $diff_time_string)
                ->with('active', $active)
                ->with('disabled', $disabled)
				->with('renew', $renew)
                ->with('user_balance', $user_balance)
                ->with('amount_due', $amount_due)
                ->with('credit_used', $credit_used)
                ->with('countries', $countries)
                ->with('states', $states)
				->with('package', $package)
                ->with('billing_descriptor', $billing_descriptor);
		}
        return view('recurly.product_details')->with('plan', $plan)
            ->with('userProxyData', $userProxyData)
            ->with('ip_list_array', $ip_list_array)
			->with('addresses_list', $addresses_list)
            ->with('authorized_ip_array', $authorized_ip_array)
            ->with('rotation_period', $rotation_period)
            ->with('regions', $regions)
            ->with('proxy_rotation', $proxy_rotation)
            ->with('expiry_date', $expiration_date)
            ->with('billing_info', $billing_info)
            ->with('account', $account)
            ->with('proxy_location', $proxy_location)
            ->with('expire_date', $expire_date)
            ->with('purchase_plan', $purchaseplan)
            ->with('diff_time_string', $diff_time_string)
            ->with('active', $active)
            ->with('disabled', $disabled)
			->with('renew', $renew)
            ->with('user_balance', $user_balance)
            ->with('amount_due', $amount_due)
            ->with('credit_used', $credit_used)
            ->with('countries', $countries)
            ->with('states', $states)
			->with('package', $package)
            ->with('billing_descriptor', $billing_descriptor);
    }

    public function showComplexCustomerProductDetails($uuid, RecurlyProducts $plan, $package = false) {
        $user_id = Auth::user()->id;
        $purchaseplan = PurchasePlans::where('customer_id',$user_id)->where('uuid',$uuid)->orderBy('id', 'desc')->firstOrFail();
        $user_balance = $this->recurlyService->getAccountBalance(Auth::user());
		$amount_due = $this->recurlyService->getPostDueAmount(Auth::user());
        $credit_used = $amount_due + $user_balance;
        if ($credit_used < 0) $credit_used = 0;
        if ($credit_used > $amount_due) $credit_used = $amount_due;
        $countries = \CountryState::getCountries();
        $rotation_period  = recurly_rotation_period2();
        $regions          = Regions::getActivated();

        $expiration_date = $purchaseplan->expiration_date;


        $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
        $billing_descriptor = $this->settings->get('billing_descriptor');
        $account = $this->recurlyService->getAccount(Auth::user());

        $expire_date = $purchaseplan->expiration_date;
        $active = $purchaseplan->isActive(); $diff_time_string = '';
        if ($active) $diff_time_string = $purchaseplan->remainingTime();
        $disabled = $purchaseplan->status == PurchasePlans::EXPIRED ? TRUE : FALSE;

		$renew = FALSE;
		if ($plan->billing_type != 'trial') {
			$subscription = $this->recurlyService->getSubscription($purchaseplan->uuid);
			if ($subscription) {
				$invoice = $subscription->invoice->get();
				if ($invoice && $invoice->state != 'collected') {
					$renew = TRUE;
				}
			}
		}

        $curr_region = Regions::where('rid',$plan->location)->first();
        if ($purchaseplan->local_status != PurchasePlans::ERROR && $purchaseplan->local_status != PurchasePlans::PROVISIONING && ($purchaseplan->status == PurchasePlans::ACTIVE || $purchaseplan->status == PurchasePlans::CANCELED || $purchaseplan->status == PurchasePlans::EXPIRED)) {
            $bundleItems = $plan->bundlePlans;
            $array_items = []; $deactivate = 0;
            foreach($bundleItems as $item) {
                $userProxyData = CustomerProxyData::where('uuid', $uuid)
                    ->where('customer_id', Auth::user()->id)
                    ->where('plan_id', $item->id)
                    ->first();

                if ($userProxyData->enabled == 0) {
                    $deactivate = 1;
                }

                $serilize_ip_list = $userProxyData->ip_list;
                if ($serilize_ip_list) {
                    $ip_list_array    = unserialize($serilize_ip_list);
                }
                $serilize_authorized_ip_list = $userProxyData->authorized_ip_list;
                $authorized_ip_array = [];
                if ($serilize_authorized_ip_list) {
                    $authorized_ip_array = unserialize($serilize_authorized_ip_list);
                }
                $array_items[$item->type] = array('ip_list' => $ip_list_array);
                $array_items['authorized_ip_list'] = $authorized_ip_array;
                $array_items['rotation_period'] = $userProxyData->rotation_period;
                $array_items['location'] = $userProxyData->location;
            }
        } elseif ($purchaseplan->status == PurchasePlans::PROVISIONING || $purchaseplan->local_status == PurchasePlans::PROVISIONING || $purchaseplan->local_status == PurchasePlans::ERROR) {
            $proxy_rotation = $plan->rotation_period;
            $proxy_location = $purchaseplan->location;
            return view('recurly.product_details_provisioning')->with('plan', $plan)
                ->with('rotation_period', $rotation_period)
                ->with('regions', $regions)
                ->with('proxy_rotation', $proxy_rotation)
                ->with('proxy_location', $proxy_location)
                ->with('expiry_date', $expiration_date)
                ->with('billing_info', $billing_info)
                ->with('account', $account)
                ->with('expire_date', $expire_date)
                ->with('purchase_plan', $purchaseplan)
                ->with('active', $active)
                ->with('disabled', $disabled)
				->with('renew', $renew)
                ->with('diff_time_string', $diff_time_string)
                ->with('user_balance', $user_balance)
                ->with('amount_due', $amount_due)
                ->with('credit_used', $credit_used)
                ->with('countries', $countries)
                ->with('states', $states)
				->with('package', $package)
                ->with('billing_descriptor', $billing_descriptor);
        }

        return view('recurly.product_details_complex')->with('plan',$plan)
            ->with('userProxyData',$userProxyData)
            ->with('bundle',$array_items)
            ->with('rotation_period',$rotation_period)
            ->with('expiry_date',$expiration_date)
            ->with('billing_info',$billing_info)
            ->with('account',$account)
            ->with('regions',$regions)
            ->with('expire_date',$expire_date)
            ->with('deactivate',$deactivate)
            ->with('purchase_plan',$purchaseplan)
            ->with('diff_time_string', $diff_time_string)
            ->with('active', $active)
            ->with('disabled', $disabled)
			->with('renew', $renew)
            ->with('curr_region',$curr_region)
            ->with('user_balance', $user_balance)
			->with('amount_due', $amount_due)
            ->with('credit_used', $credit_used)
            ->with('countries', $countries)
            ->with('states', $states)
			->with('package', $package)
            ->with('billing_descriptor', $billing_descriptor);
    }

    public function showDedicatedCustomerProductDetails($uuid, RecurlyProducts $plan, $package = false) {
        $user_id = Auth::user()->id;
        $purchaseplan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $user_balance = $this->recurlyService->getAccountBalance(Auth::user());
        $amount_due = $this->recurlyService->getPostDueAmount(Auth::user());
        $credit_used = $amount_due + $user_balance;
        if ($credit_used < 0) $credit_used = 0;
        if ($credit_used > $amount_due) $credit_used = $amount_due;
        $countries = \CountryState::getCountries();

        $rotation_period = recurly_rotation_period2();

        $regions = Regions::getActivated();

        $expiration_date = $purchaseplan->expiration_date;

        $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
        $billing_descriptor = $this->settings->get('billing_descriptor');
        $account = $this->recurlyService->getAccount(Auth::user());

        $expire_date = $purchaseplan->expiration_date;
        $active = $purchaseplan->isActive(); $diff_time_string = '';
        if ($active) $diff_time_string = $purchaseplan->remainingTime();
        $disabled = $purchaseplan->status == PurchasePlans::EXPIRED ? TRUE : FALSE;
        $curr_region = Regions::where('rid',$plan->location)->first();

		$renew = FALSE;
		if ($plan->billing_type != 'trial') {
			$subscription = $this->recurlyService->getSubscription($purchaseplan->uuid);
			if ($subscription) {
				$invoice = $subscription->invoice->get();
				if ($invoice && $invoice->state != 'collected') {
					$renew = TRUE;
				}
			}
		}

        if ($purchaseplan->local_status != PurchasePlans::ERROR && $purchaseplan->local_status != PurchasePlans::PROVISIONING && ($purchaseplan->status == PurchasePlans::ACTIVE || $purchaseplan->status == PurchasePlans::CANCELED || $purchaseplan->status == PurchasePlans::EXPIRED)) {
            $userProxyData = CustomerProxyData::where('uuid', $uuid)
                ->where('customer_id', Auth::user()->id)
                ->where('plan_id', $plan->id)
                ->get();
            $array_items = []; $deactivate = 0;
            foreach($userProxyData as $userProxyItem) {
                if ($userProxyItem->enabled == 0) {
                    $deactivate = 1;
                }

                $serilize_ip_list = $userProxyItem->ip_list;
                if ($serilize_ip_list) {
                    $ip_list_array    = unserialize($serilize_ip_list);
                }
				$serilize_addresses_list = $userProxyItem->addresses;
				$addresses_list = [];
				if ($serilize_addresses_list) {
					$addresses = unserialize($serilize_addresses_list);
					foreach($addresses as $id=>$addresses_item) {
						$addresses_list[$addresses_item['port']] = $addresses_item['address'];
					}
				}
                $serilize_authorized_ip_list = $userProxyItem->authorized_ip_list;
                $authorized_ip_array = [];
                if ($serilize_authorized_ip_list) {
                    $authorized_ip_array = unserialize($serilize_authorized_ip_list);
                }
                $array_items[$userProxyItem->type] = array('ip_list' => $ip_list_array, 'addresses_list'=>$addresses_list);
                $array_items['authorized_ip_list'] = $authorized_ip_array;
                $array_items['rotation_period'] = $userProxyItem->rotation_period;
                $array_items['location'] = $userProxyItem->location;
            }
        } elseif ($purchaseplan->status == PurchasePlans::PROVISIONING || $purchaseplan->local_status == PurchasePlans::PROVISIONING || $purchaseplan->local_status == PurchasePlans::ERROR) {
            $proxy_rotation = $plan->rotation_period;
            return view('recurly.product_details_provisioning')->with('plan', $plan)
                ->with('rotation_period', $rotation_period)
                ->with('regions', $regions)
                ->with('proxy_rotation', $proxy_rotation)
                ->with('expiry_date', $expiration_date)
                ->with('billing_info', $billing_info)
                ->with('account', $account)
                ->with('expire_date', $expire_date)
                ->with('purchase_plan', $purchaseplan)
                ->with('diff_time_string', $diff_time_string)
                ->with('active', $active)
                ->with('disabled', $disabled)
				->with('renew', $renew)
                ->with('user_balance', $user_balance)
                ->with('amount_due', $amount_due)
                ->with('credit_used', $credit_used)
                ->with('countries', $countries)
                ->with('states', $states)
				->with('package', $package)
                ->with('billing_descriptor', $billing_descriptor);
        }

        return view('recurly.product_details_dedicated')->with('plan', $plan)
            ->with('userProxyData', $userProxyItem)
            ->with('bundle', $array_items)
            ->with('rotation_period', $rotation_period)
            ->with('regions', $regions)
            ->with('expiry_date', $expiration_date)
            ->with('billing_info', $billing_info)
            ->with('account', $account)
            ->with('expire_date', $expire_date)
            ->with('deactivate',$deactivate)
            ->with('purchase_plan', $purchaseplan)
            ->with('curr_region',$curr_region)
            ->with('diff_time_string', $diff_time_string)
            ->with('active', $active)
            ->with('disabled', $disabled)
			->with('renew', $renew)
            ->with('user_balance', $user_balance)
            ->with('amount_due', $amount_due)
            ->with('credit_used', $credit_used)
            ->with('countries', $countries)
            ->with('states', $states)
			->with('package', $package)
            ->with('billing_descriptor', $billing_descriptor);
    }

    public function showVPNCustomerProductDetails($uuid, RecurlyProducts $plan, $package = false) {
		$user_id = Auth::user()->id;

		$purchaseplan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $user_balance = $this->recurlyService->getAccountBalance(Auth::user());
        $amount_due = $this->recurlyService->getPostDueAmount(Auth::user());
        $credit_used = $amount_due + $user_balance;
        if ($credit_used < 0) $credit_used = 0;
        if ($credit_used > $amount_due) $credit_used = $amount_due;
        $countries = \CountryState::getCountries();

		$billing_info = $this->recurlyService->getBillingInfo(Auth::user());
		$account = $this->recurlyService->getAccount(Auth::user());
        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
        $billing_descriptor = $this->settings->get('billing_descriptor');
		$active = $purchaseplan->isActive(); $diff_time_string = '';
		if ($active) $diff_time_string = $purchaseplan->remainingTime();

		$renew = FALSE;
		if ($plan->billing_type != 'trial') {
			$subscription = $this->recurlyService->getSubscription($purchaseplan->uuid);
			if ($subscription) {
				$invoice = $subscription->invoice->get();
				if ($invoice && $invoice->state != 'collected') {
					$renew = TRUE;
				}
			}
		}

        $disabled = $purchaseplan->status == PurchasePlans::EXPIRED ? TRUE : FALSE;
        if ($purchaseplan->local_status != PurchasePlans::ERROR && $purchaseplan->local_status != PurchasePlans::PROVISIONING && ($purchaseplan->status == PurchasePlans::ACTIVE || $purchaseplan->status == PurchasePlans::CANCELED || $purchaseplan->status == PurchasePlans::EXPIRED)) {
            $vpnData = CustomerVPNData::where('uuid', $uuid)
                ->where('customer_id', Auth::user()->id)
                ->where('plan_id', $plan->id)
                ->first();

			$assignedUsers = CustomerVPNServerUsers::with('vpnuser')->where('vpn_data_id', $vpnData->id)->get();
			$count_users = $assignedUsers->count();

            return view('recurly.product_details_vpn')
                ->with('plan', $plan)
                ->with('purchaseplan', $purchaseplan)
                ->with('vpnData', $vpnData)
                ->with('assignedUsers', $assignedUsers)
                ->with('count_users', $count_users)
                ->with('billing_info', $billing_info)
                ->with('account', $account)
                ->with('diff_time_string', $diff_time_string)
                ->with('purchase_plan', $purchaseplan)
                ->with('active', $active)
                ->with('disabled', $disabled)
				->with('renew', $renew)
                ->with('user_balance', $user_balance)
                ->with('amount_due', $amount_due)
                ->with('credit_used', $credit_used)
                ->with('countries', $countries)
                ->with('states', $states)
				->with('package', $package)
                ->with('billing_descriptor', $billing_descriptor);
        } else {
            return view('recurly.product_details_vpn_provisioning')
                ->with('plan', $plan)
                ->with('purchaseplan', $purchaseplan)
                ->with('billing_info', $billing_info)
                ->with('account', $account)
                ->with('diff_time_string', $diff_time_string)
                ->with('purchase_plan', $purchaseplan)
                ->with('active', $active)
                ->with('disabled', $disabled)
				->with('renew', $renew)
                ->with('user_balance', $user_balance)
                ->with('amount_due', $amount_due)
                ->with('credit_used', $credit_used)
                ->with('countries', $countries)
                ->with('states', $states)
				->with('package', $package)
                ->with('billing_descriptor', $billing_descriptor);
        }
    }

	public function showRouterCustomerProductDetails($uuid, RecurlyProducts $plan, $package = false) {
		$user_id = Auth::user()->id;

		$purchaseplan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();

		$active = $purchaseplan->isActive(); $diff_time_string = '';
		if ($active) $diff_time_string = $purchaseplan->remainingTime();

		$disabled = $purchaseplan->status == PurchasePlans::EXPIRED ? TRUE : FALSE;
		$renew = FALSE;
		if ($plan->billing_type != 'trial') {
			$subscription = $this->recurlyService->getSubscription($purchaseplan->uuid);
			if ($subscription) {
				$invoice = $subscription->invoice->get();
				if ($invoice && $invoice->state != 'collected') {
					$renew = TRUE;
				}
			}
		}

		if ($purchaseplan->local_status != PurchasePlans::ERROR && $purchaseplan->local_status != PurchasePlans::PROVISIONING && ($purchaseplan->status == PurchasePlans::ACTIVE || $purchaseplan->status == PurchasePlans::CANCELED || $purchaseplan->status == PurchasePlans::EXPIRED)) {
            $routerData = CustomerRouterData::where('uuid', $uuid)
                ->where('customer_id', Auth::user()->id)
                ->where('plan_id', $plan->id)
                ->first();
            $vpn_server = $routerData->vpn_server;
            $available_vpn = CustomerVPNData::whereHas('plan', function ($query) {
				$query->where('plan_type', '=', 'vpn_dedicated')
					->where('city', '!=', 5)->where('city', '!=', 7)
					->where('billing_type', '!=', 'trial');
			})->where('customer_id', Auth::user()->id)->where('enabled', 1)->where('expired', 0)->get();

			$response = $this->routerService->loadLocations(Auth::user());
			$dedicated_vpn_loc = [];
			$high_secure_loc = []; $extreme_secure_loc = [];
			if ($response['success']) {
				foreach($response['locations'] as $location) {
					if ($location->id == $routerData->location_id) $location->active = true;
						else $location->active = false;
					if ($location->is_starcore) $extreme_secure_loc[] = $location;
						else $high_secure_loc[] = $location;
				}

				foreach($available_vpn as $vpn) {
					foreach($response['servers'] as $location) {
						if ($location->ip_address == $vpn->server_ip_address) {
							$location->server_id = $vpn->server_id;
							if ($vpn_server && $location->server_id == $vpn_server->server_id) $location->active = true;
								else $location->active = false;
							$dedicated_vpn_loc[] = $location;
							break;
						}
					}
				}
			}

			return view('recurly.product_details_router', [
                'plan' => $plan,
                'purchase_plan' => $purchaseplan,
                'routerData' => $routerData,
                'vpn_server' => $vpn_server,
                'available_vpn' => $available_vpn,
                'active' => $active,
                'disabled' => $disabled,
				'renew' => $renew,
                'diff_time_string' => $diff_time_string,
				'package' => $package,
				'dedicated_vpn_loc' => $dedicated_vpn_loc,
				'high_secure_loc' => $high_secure_loc,
				'extreme_secure_loc' => $extreme_secure_loc,
            ]);
		} else {
			return view('recurly.product_details_router_provisioning', [
                'plan' => $plan,
                'purchase_plan' => $purchaseplan,
                'active' => $active,
                'disabled' => $disabled,
				'renew' => $renew,
                'diff_time_string' => $diff_time_string,
				'package' => $package,
            ]);
		}
	}

	public function productDetailForCustomers($uuid, $plan_id) {
		$purchase_plan  = PurchasePlans::where('uuid',$uuid)->where('status', '!=', 'expired')->with('plan')->firstOrFail();
		$plan = $purchase_plan->plan; $package = FALSE;
		if ($plan->plan_type == 'package') {
			$package = TRUE;
			$bundle_plans = $plan->bundlePlans()->get();
			foreach($bundle_plans as $bundle_plan) {
				if ($bundle_plan->id == $plan_id) $plan = $bundle_plan;
			}
		}

		if ($plan && $plan->plan_type=="simple") {
            return $this->showSimpleCustomerProductDetails($uuid, $plan, $package);
        } elseif ($plan->plan_type == 'dedicated') {
            return $this->showDedicatedCustomerProductDetails($uuid, $plan, $package);
		} elseif ($plan->plan_type == "complex") {
            return $this->showComplexCustomerProductDetails($uuid, $plan, $package);
        } elseif ($plan->plan_type == 'vpn_dedicated') {
			return $this->showVPNCustomerProductDetails($uuid, $plan, $package);
		} elseif ($plan->plan_type == 'router') {
			return $this->showRouterCustomerProductDetails($uuid, $plan, $package);
		} else {
			return Redirect::back()->withErrors([
				'error' => 'Product Not Found! ',
			]);
		}
	}

    public function getFixErrorServices(Request $request, $uuid, $plan_id) {
        $purchasePlan = PurchasePlans::where('uuid', $uuid)
            ->where('customer_id', $request->user()->id)
            ->where('local_status', PurchasePlans::ERROR)
            ->firstOrFail();
        $plan = $purchasePlan->plan;
        try {
            if ($plan->plan_type == 'vpn_dedicated') {
                $response = $this->vpnService->createPurchaseVPNServer($purchasePlan, $plan, $request->user());
            } else if ($plan->plan_type == 'router') {
                $response = $this->routerService->createPurchaseRouterService($purchasePlan, $plan, $request->user());
            } else {
                $response = $this->proxyService->createPurchasedProxyService($purchasePlan, $plan, $request->user());
            }
            if ($response['success'] == FALSE) {
                return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('error', $response['error']);
            }
            if (isset($response['message']) && !empty($response['message'])) {
                return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('success', 'Service has activated and ' . $response['message']);
            } else {
                return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('success', 'Service has activated');
            }
        } catch(\Exception $e) {
            return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('error', $e->getMessage());
        }
    }

	public function postAuthorizedIpsRequest(Request $request, $uuid, $plan_id){
		$this->validate($request, [
			'authorized_ip_list' => 'required',
		]);
		$purchasePlans = PurchasePlans::where('uuid', $uuid)->with('plan')->firstOrFail();
		$plan = $purchasePlans->plan;
		if ($plan->plan_type == 'package') {
			$plan = $plan->bundlePlans()->where('recurly_products.id', $plan_id)->firstOrFail();
		}

		if ($plan) {
			if ($plan->plan_type=='simple') {
                $region = Input::get('region');
                $protocol = Input::get('protocol');
                $rotation_period = Input::get('rotation_period');
                $authorized_ip_list = Input::get('authorized_ip_list');
                $authorized_ip_list_array = explode("\r\n", trim($authorized_ip_list));
                $authorized_ip_list_array = array_map('trim', $authorized_ip_list_array);

                $data = array(
                    "store_proxy_id" => $purchasePlans->id . '-' . $plan->id,
                    "region" => $region,
                    "rotation_period" => $rotation_period,
                    "ip_list" => $authorized_ip_list_array,
                    "protocol" => $protocol,
                    "store_account_id" => Auth::user()->user_identifier,
                );
                //encoding to json format
                $jsondata = json_encode($data);
//				return $jsondata;
                $url = env('STOREPROXY_CHANGE');
                try {
                    $data = curlWrap("storeproxy", $url, $jsondata, "POST");
                    if (isset($data)) {
                        $message = $data->message;
                    } else {
                        throw new Exception('Store proxy is not responding! Please try later Thanks');
                    }
                } catch (Exception $e) {
                    return Redirect::back()->withErrors([
                        'error' => 'Store proxy is not responding! Please try later Thanks',
                    ]);
                }

                $serilize_authorized_ips = serialize($authorized_ip_list_array);

                if ($serilize_authorized_ips && $message == "Proxy service has been changed successfully") {
                    $storeAuthorizedIpList = CustomerProxyData::where('uuid', $uuid)->update([
                        'authorized_ip_list' => $serilize_authorized_ips,
                        'rotation_period' => $rotation_period,
                        'location' => $region,
                        'protocol' => $protocol
                    ]);
                }
                return Redirect::back()->with('success', $message);
            } elseif ($plan->plan_type == 'dedicated') {
                $protocol = Input::get('protocol');
                $authorized_ip_list = Input::get('authorized_ip_list');
                $authorized_ip_list_array = explode("\r\n", trim($authorized_ip_list));
                $authorized_ip_list_array = array_map('trim', $authorized_ip_list_array);

                $proxy_ids = [
                    1 => $purchasePlans->id . '-' . $plan->id . '-1',
                    2 => $purchasePlans->id . '-' . $plan->id . '-2',
                ];

                foreach ($proxy_ids as $id => $proxy_id) {
                    if ($id == 2) $type = 'dedicated_turbospin';
                        else $type = 'dedicated';
                    $proxy_data = CustomerProxyData::where('uuid', $uuid)->where('type', $type)->first();
                    $data = array(
                        "store_proxy_id" => $proxy_id,
                        "region" => $proxy_data->location,
                        "rotation_period" => $proxy_data->rotation_period,
                        "ip_list" => $authorized_ip_list_array,
                        "protocol" => $protocol,
                        "store_account_id" => Auth::user()->user_identifier,
                    );

                    $jsondata = json_encode($data);
                    $url = env('STOREPROXY_CHANGE');
                    try {
                        $data = curlWrap("storeproxy", $url, $jsondata, "POST");
                        if (isset($data)) {
                            $message = $data->message;
                        } else {
                            throw new Exception('Store proxy is not responding! Please try later Thanks');
                        }
                    } catch (Exception $e) {
                        return Redirect::back()->withErrors([
                            'error' => 'Store proxy is not responding! Please try later Thanks',
                        ]);
                    }

                    $serilize_authorized_ips = serialize($authorized_ip_list_array);

                    if ($serilize_authorized_ips && $message == "Proxy service has been changed successfully") {
                        if ($id == 2) $type = 'dedicated_turbospin';
                        else $type = 'dedicated';
                        $storeAuthorizedIpList = CustomerProxyData::where('uuid', $uuid)->where('type', $type)->update([
                            'authorized_ip_list' => $serilize_authorized_ips,
                            'protocol' => $protocol
                        ]);
                    }
                }
                return Redirect::back()->with('success', $message);
            } elseif ($plan->plan_type=='dedicated_simple') {
                $protocol = Input::get('protocol');
                $authorized_ip_list = Input::get('authorized_ip_list');
                $authorized_ip_list_array = explode("\r\n", trim($authorized_ip_list));
                $authorized_ip_list_array = array_map('trim', $authorized_ip_list_array);

                $proxy_data = CustomerProxyData::where('uuid', $uuid)->first();
                $data = array(
                    "store_proxy_id" => $purchasePlans->id . '-' . $plan->id,
                    "region" => $proxy_data->location,
                    "rotation_period" => $proxy_data->rotation_period,
                    "ip_list" => $authorized_ip_list_array,
                    "protocol" => $protocol,
                    "store_account_id" => Auth::user()->user_identifier,
                );

                $jsondata = json_encode($data);
                $url = env('STOREPROXY_CHANGE');
                try {
                    $data = curlWrap("storeproxy", $url, $jsondata, "POST");
                    if (isset($data)) {
                        $message = $data->message;
                    } else {
                        throw new Exception('Store proxy is not responding! Please try later Thanks');
                    }
                } catch (Exception $e) {
                    return Redirect::back()->withErrors([
                        'error' => 'Store proxy is not responding! Please try later Thanks',
                    ]);
                }

                $serilize_authorized_ips = serialize($authorized_ip_list_array);

                if ($serilize_authorized_ips && $message == "Proxy service has been changed successfully") {
                    $storeAuthorizedIpList = CustomerProxyData::where('uuid', $uuid)->update([
                        'authorized_ip_list' => $serilize_authorized_ips,
                        'protocol' => $protocol
                    ]);
                }
                return Redirect::back()->with('success', $message);

			} elseif ($plan->plan_type=='complex') {
				$bundleItems = $plan->bundlePlans;		

				foreach($bundleItems as $item) {
					$region             = Input::get('region');
					$rotation_period    = Input::get('rotation_period');
					$protocol			= Input::get('protocol');
					$authorized_ip_list = Input::get('authorized_ip_list');
					$authorized_ip_list_array = explode("\r\n",trim($authorized_ip_list));
					$authorized_ip_list_array = array_map('trim',$authorized_ip_list_array);
					
					$data = array(
						"store_proxy_id"    =>  $purchasePlans->id.'-'.$item->id,
						"region"            =>  $region,
						"rotation_period"   =>  $rotation_period,
						"ip_list"           =>  $authorized_ip_list_array,
						"protocol"			=>  $protocol,
						"store_account_id"  =>  Auth::user()->user_identifier,
					);
					$jsondata = json_encode($data);
					Log::info('Proxy Call'.$jsondata);				
					$url = env('STOREPROXY_CHANGE');

					try{
						$data = curlWrap("storeproxy",$url, $jsondata, "POST");
						if(isset($data)){
							$message = $data->message;
						}else{
							throw new Exception('Store proxy is not responding! Please try later Thanks');
						}
					} catch (Exception $e){
						return Redirect::back()->withErrors([
							'error' => 'Store proxy is not responding! Please try later Thanks',
						]);
					}

					$serilize_authorized_ips  =  serialize($authorized_ip_list_array);

					if($serilize_authorized_ips && $message=="Proxy service has been changed successfully"){

						$storeAuthorizedIpList = CustomerProxyData::where('uuid',$uuid)
							->where('customer_id',Auth::user()->id)->update([
								'authorized_ip_list' => $serilize_authorized_ips,
								'rotation_period'    => $rotation_period,
								'location'			 => $region,
								"protocol"			=>	$protocol
							]);
					}
				
				}
				return Redirect::back()->with('success',$message);
			}

		}else{
			return Redirect::back()->withErrors([
				'error' => 'Invalid Product! ',
			]);
		}
	}


	public function getAddedProductDetailAjax(Request $request){
		if ($request->ajax()) {
			$plan_code        = Input::get('plan_code');
			$addedProduct     = RecurlyProducts::where('plan_code',$plan_code)->first();

			return response()->json(array(
				'added_product' => (String)view('recurly.admin.partial_added_product')
					->with('addedProduct',$addedProduct),
			'ptype'=>$addedProduct->type));
		}
	}


	public function getDurationForPlan(Request $request){
		if ($request->ajax()) {
			$billing_type = Input::get('billing_type');
			$prod_type = Input::get('prod_type');
			return response()->json(array(
				'duration_list' => (String)view('recurly.admin.partial_duration_field')
					->with('billing_type',$billing_type)->with('prod_type', $prod_type),
			));
		}
	}

	public function updateParamsCustomPlan(Request $request,$plan_id) {

		$value1 = Input::get('switch_timer_selectbox');
		$value2 = Input::get('geo_switch_selectbox');
		$value3 = Input::get('protocol_switch_selectbox');
		$userid = Auth::user()->id;
		$data = CustomerProxyData::where('customer_id', $userid)
										->where('plan_id', $plan_id)
										->orderBy('id', 'desc')
										->first();

		if(!empty($value1)) {
			$data->update(['rotation_period'=>$value1]);
		}

		elseif (!empty($value2)) {
			$data->update(['location'=>$value2]);
		}

		elseif (!empty($value3)) {
			$data->update(['protocol'=>$value3]);
		}

		return Redirect::back()->with('success',"Updated Successfully");

	}

	public function recurlyProductsTypeNewGuest($type = 'vpn', Request $request) {
		$types = ['vpn', 'router'];
		if (!in_array($type, $types)) $type = 'vpn';
		return $this->showRecurlyProductsNewGuest($type, $request);
	}

	public function recurlyProductsNewGuest(Request $request) {
		return $this->showRecurlyProductsNewGuest(null, $request);
	}

	protected function showRecurlyProductsNewGuest($type = null, Request $request) {
		$countries = \CountryState::getCountries();
		$states = \CountryState::getStates('US');
		$billing_descriptor = $this->settings->get('billing_descriptor');

		//$proxyCategory = RecurlyCategories::where('id', 1)->first();
		$vpnCategory = RecurlyCategories::where('id', 42)->first();
		$vpnCategoryChildren = $vpnCategory->getChildrenCategories()->where('trial', 0)->get();

		$routerCategory = RecurlyCategories::where('id', 78)->first();
		$routerCategoryChildren = $routerCategory->getChildrenCategories()->where('trial', 0)->get();
		$categories = [
			/*'proxy' => $proxyCategory,*/
			'vpn' => [
				'main' => $vpnCategory,
				'children' => $vpnCategoryChildren,
			],
			'router' => [
				'main' => $routerCategory,
				'children' => $routerCategoryChildren,
			],
		];

		$selected_plan = null; $products = null; $plan_cat = null;
		$discount = [];
		$child_cats = []; $proxy_units = null; $locations = null;
		$category_tree_ids = [];

		$selected_plan = null;
		if ($request->has('plan_code')) {
			$selected_plan = RecurlyProducts::where('plan_code', $request->get('plan_code'))->first();
		}

		if ($selected_plan) {
			$show_empty_cats = $this->settings->get('show_empty_cats');
			$plan_cat = RecurlyCategories::where('id', $selected_plan->category_id)->first();
			$category_tree = $plan_cat->getCategoryTree();
			foreach($category_tree as $tree_item) {
				$category_tree_ids[] = $tree_item->id;
				if ($tree_item->id == 42) $type = 'vpn';
				if ($tree_item->id == 78) $type = 'router';
			}

			$exclude_ids = RecurlyCategories::where('parent_category_id', 0)->lists('id');
			foreach($category_tree as $key=>$category_item) {
				if (in_array($category_item->id, $exclude_ids->toArray())) unset($category_tree[$key]);
			}
			$category_tree = array_reverse($category_tree);

			$counts = [];
			foreach($category_tree as $category_item) {
				$children_items = $category_item->getChildrenCategories()->where('trial', 0)->get();
				foreach($children_items as $id=>$child) {
					$child_cats_list = $child->getChildrenList();
					$child_cats_list[] = $child->id;
					if (!$show_empty_cats) {
						$prod_count_query = RecurlyProducts::whereIn('category_id', $child_cats_list)->where('plan_availability','in_stock')->whereNotIn('billing_type',['trial']);
						$prod_count = $prod_count_query->count();
					} else $prod_count = 1;
					$counts[$child->id] = $prod_count;
				}
			}

			$child_cats = []; $level = 2;
			foreach($category_tree as $category_item) {
				$children_items = $category_item->getChildrenCategories()->where('trial', 0)->get();
				$child_cats[$category_item->id] = ['items' => [], 'has_description' => false, 'level' => $level, 'level_name' => null]; $has_description = FALSE;
				foreach($children_items as $id=>$child) {
					if (isset($counts[$child->id]) && $counts[$child->id] > 0) {
						$cat_item = new \stdClass();
						$cat_item->id = $child->id;
						$cat_item->name = $child->name;
						if (!empty($child->description)) $has_description = TRUE;
						$cat_item->description = $child->description;
						if ($child->getChildrenCategories->count()) {
							$cat_item->hasChild = true;
						} else $cat_item->hasChild = false;

						$child_cats[$category_item->id]['has_description'] = $has_description;
						$child_cats[$category_item->id]['level_name'] = $child->level_name;
						array_push($child_cats[$category_item->id]['items'], $cat_item);
					}
				}
				$level++;
			}

			$products = RecurlyProducts::where('category_id', $selected_plan->category_id)
				->where('plan_availability','in_stock')
				->where('plan_quantity', $selected_plan->plan_quantity)
				->where('location', $selected_plan->location)
				->where('city', $selected_plan->city)
				->get();
			foreach($products as $product) {
				if (!isset($discount[$product->category_id])) {
					$discount_settings = $this->settings->get('discounts_'.$product->category_id);
					if ($discount_settings) {
						$discount_settings = unserialize($discount_settings);
						$discount[$product->category_id] = $discount_settings;
					}
				}
			}

			$proxy_units_query = RecurlyProducts::where('category_id', $selected_plan->category_id)->where('billing_type','duration')
				->where('plan_type', '!=', 'vpn_dedicated');
			$proxy_units = $proxy_units_query->lists('plan_quantity')->unique()->sort();
			if ($selected_plan->category_id == 120 || $selected_plan->category_id == 122){
				$products_locations = RecurlyProducts::lists('location')
					->unique()->flatten()->reject(function ($value) {
						return $value == 11;
					})->toArray();
				$locations = array_only(Regions::getActivated(), $products_locations);
			} else {
				$locations = '';
			}
		}

		$viewData = [
			'countries' => $countries,
			'states' => $states,
			'categories' => $categories,
			'billing_descriptor' => $billing_descriptor,
			'type' => $type,
			'products' => $products,
			'selected_plan' => $selected_plan,
			'discount' => $discount,
			'plan_cat' => $plan_cat,
			'category_tree_ids' => $category_tree_ids,
			'child_cats' => $child_cats,
			'proxy_units' => $proxy_units,
			'locations' => $locations,
			'enable_vat' => $this->settings->get('enable_vat'),
			'terms' => $this->settings->get('terms'),
			'user_balance' => 0,
		];

		return view('recurly.guest.recurly_product_new', $viewData);
	}

	public function recurlyProductsNew(Request $request) {
		//$locations = $this->vpnService->loadLocations($request->user());
		//p($locations); exit;

		return $this->showRecurlyProductsNew(null, $request);
	}

	public function recurlyProductsTypeNew($type = 'vpn', Request $request) {
		$types = ['vpn', 'router'];
		if (!in_array($type, $types)) $type = 'vpn';
		return $this->showRecurlyProductsNew($type, $request);
	}

    /*
     * Action to display Add Services page
     * ToDo: need to refactor this action
     */
	protected function showRecurlyProductsNew($type = 'vpn', Request $request) {
		$past_due = $this->recurlyService->getPostDueAmount($request->user());
		if ($past_due > 0) {
			return redirect()->action('BillingController@getInvoices')->with('action', 'past_due');
		}

		$sorting_options = recurly_sorting_options();
		$user_balance = $this->recurlyService->getAccountBalance($request->user(), FALSE);

		if (Auth::user()->role=="admin") {
            return redirect()->action('Admin\ProductsController@recurlyProducts');
		} else {
            $countries = \CountryState::getCountries();

            $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
            $account = $this->recurlyService->getAccount(Auth::user());

			$states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
			$billing_descriptor = $this->settings->get('billing_descriptor');

			//$proxyCategory = RecurlyCategories::where('id', 1)->first();

			$vpnCategory = RecurlyCategories::where('id', 42)->first();
			$vpnCategoryChildren = $vpnCategory->getChildrenCategories()->where('trial', 0)->get();

			$routerCategory = RecurlyCategories::where('id', 78)->first();
			$routerCategoryChildren = $routerCategory->getChildrenCategories()->where('trial', 0)->get();
			$categories = [
				/*'proxy' => $proxyCategory,*/
				'vpn' => [
					'main' => $vpnCategory,
					'children' => $vpnCategoryChildren,
				],
				'router' => [
					'main' => $routerCategory,
					'children' => $routerCategoryChildren,
				],
			];

            $selected_plan = null; $products = null;
            $plan_cat = null; $plan_subcat = null; $plan_main_cat = null;
            $discount = [];
            $child_cats = []; $proxy_units = null; $locations = null;
			$category_tree_ids = [];

			$selected_plan = null;
			if ($request->has('plan_code')) {
				$selected_plan = RecurlyProducts::where('plan_code', $request->get('plan_code'))->first();
			}
			if ($selected_plan) {
				$show_empty_cats = $this->settings->get('show_empty_cats');
				$plan_cat = RecurlyCategories::where('id', $selected_plan->category_id)->first();
				$category_tree = $plan_cat->getCategoryTree();
				foreach($category_tree as $tree_item) {
					$category_tree_ids[] = $tree_item->id;
					if ($tree_item->id == 42) $type = 'vpn';
					if ($tree_item->id == 78) $type = 'router';
				}

				$exclude_ids = RecurlyCategories::where('parent_category_id', 0)->lists('id');
				foreach($category_tree as $key=>$category_item) {
					if (in_array($category_item->id, $exclude_ids->toArray())) unset($category_tree[$key]);
				}
				$category_tree = array_reverse($category_tree);

				$counts = [];
				foreach($category_tree as $category_item) {
					$children_items = $category_item->getChildrenCategories()->where('trial', 0)->get();
					foreach($children_items as $id=>$child) {
						$child_cats_list = $child->getChildrenList();
						$child_cats_list[] = $child->id;
						if (!$show_empty_cats) {
							$prod_count_query = RecurlyProducts::whereIn('category_id', $child_cats_list)->where('plan_availability','in_stock')->whereNotIn('billing_type',['trial']);
							$prod_count = $prod_count_query->count();
						} else $prod_count = 1;
						$counts[$child->id] = $prod_count;
					}
				}

				$child_cats = []; $level = 2;
				foreach($category_tree as $category_item) {
					$children_items = $category_item->getChildrenCategories()->where('trial', 0)->get();
					$child_cats[$category_item->id] = ['items' => [], 'has_description' => false, 'level' => $level, 'level_name' => null]; $has_description = FALSE;
					foreach($children_items as $id=>$child) {
						if (isset($counts[$child->id]) && $counts[$child->id] > 0) {
							$cat_item = new \stdClass();
							$cat_item->id = $child->id;
							$cat_item->name = $child->name;
							if (!empty($child->description)) $has_description = TRUE;
							$cat_item->description = $child->description;
							if ($child->getChildrenCategories->count()) {
								$cat_item->hasChild = true;
							} else $cat_item->hasChild = false;

							$child_cats[$category_item->id]['has_description'] = $has_description;
							$child_cats[$category_item->id]['level_name'] = $child->level_name;
							array_push($child_cats[$category_item->id]['items'], $cat_item);
						}
					}
					$level++;
				}

				$products = RecurlyProducts::where('category_id', $selected_plan->category_id)
					->where('plan_availability','in_stock')
					->where('plan_quantity', $selected_plan->plan_quantity)
					->where('location', $selected_plan->location)
					->where('city', $selected_plan->city)
					->get();
				foreach($products as $product) {
					if (!isset($discount[$product->category_id])) {
						$discount_settings = $this->settings->get('discounts_'.$product->category_id);
						if ($discount_settings) {
							$discount_settings = unserialize($discount_settings);
							$discount[$product->category_id] = $discount_settings;
						}
					}
				}

				$proxy_units_query = RecurlyProducts::where('category_id', $selected_plan->category_id)->where('billing_type','duration')
					->where('plan_type', '!=', 'vpn_dedicated');
				$proxy_units = $proxy_units_query->lists('plan_quantity')->unique()->sort();
				if ($selected_plan->category_id == 120 || $selected_plan->category_id == 122){
					$products_locations = RecurlyProducts::lists('location')
						->unique()->flatten()->reject(function ($value) {
							return $value == 11;
						})->toArray();
					$locations = array_only(Regions::getActivated(), $products_locations);
				} else {
					$locations = '';
				}
			}

			$viewData = [
				'billing_info' => $billing_info,
				'countries' => $countries,
				'states' => $states,
				'account' => $account,
				'sorting_options' => $sorting_options,
				'categories' => $categories,
				'billing_descriptor' => $billing_descriptor,
				'type' => $type,
				'user_balance' => $user_balance,
                'products' => $products,
                'selected_plan' => $selected_plan,
                'discount' => $discount,
                'plan_cat' => $plan_cat,
				'category_tree_ids' => $category_tree_ids,
				'child_cats' => $child_cats,
                'proxy_units' => $proxy_units,
                'locations' => $locations,
				'enable_vat' => $this->settings->get('enable_vat'),
				'terms' => $this->settings->get('terms'),
			];
			if ($type == 'router') {
				$viewData['active_vpn'] = CustomerVPNData::where('customer_id', Auth::user()->id)->where('enabled', 1)->where('expired', 0)->count();
			}
			return view('recurly.recurly_product_new', $viewData);
		}
	}

	public function getSubcategories(Request $request) {
		$show_empty_cats = $this->settings->get('show_empty_cats');
		if ($request->ajax()) {
			$category_id = $request->get('cat_id');
			$level = $request->get('level');
			$purchased_plan_uuid = $request->get('uuid'); $plan = null; $purchase_plan = null;
			if ($purchased_plan_uuid) {
				$user_id = $request->user()->id;
				$purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $purchased_plan_uuid)->orderBy('id', 'desc')->firstOrFail();
				$plan = $purchase_plan->plan;
			}

			$category = RecurlyCategories::findorfail($category_id);
			$children = $category->getChildrenCategories()->where('trial', 0)->get(); $counts = [];
			foreach($children as $id=>$child) {
				$child_cats_list = $child->getChildrenList();
				$child_cats_list[] = $child->id;
				if (!$show_empty_cats) {
					$prod_count_query = RecurlyProducts::whereIn('category_id', $child_cats_list)->where('plan_availability','in_stock')->whereNotIn('billing_type',['trial']);
					if ($plan) {
						if ($plan->plan_type == 'vpn_dedicated') {
							$prod_count_query->where('plan_type', 'vpn_dedicated');
							$prod_count_query->where('city', $plan->city);
							$prod_count_query->where('vpn_users', '>', $plan->vpn_users);
							$prod_count_query = $prod_count_query->where('price', '>=', $plan->price);
							$type = 'vpn';
						} else {
							$prod_count_query = $prod_count_query->where('price', '>', $plan->price);
							$prod_count_query->whereIn('plan_type', ['simple', 'complex', 'dedicated']);
							$type = 'proxy';
						}
					}
					$prod_count = $prod_count_query->count();
				} else $prod_count = 1;
				$counts[$child->id] = $prod_count;
			}

			$child_cats = array(); $has_description = FALSE; $level_name = null;
			foreach ($children as $child) {
				if (isset($counts[$child->id]) && $counts[$child->id] > 0) {
					$cat_item = new \stdClass();
					$cat_item->id = $child->id;
					$cat_item->name = $child->name;
					$level_name = $child->level_name;
					if (!empty($child->description)) $has_description = TRUE;
					$cat_item->description = $child->description;
					if ($child->getChildrenCategories->count()) {
						$cat_item->hasChild = true;
					} else $cat_item->hasChild = false;

					array_push($child_cats, $cat_item);
				}
			}

			return response()->json(array(
				'categories' => (String)view('recurly.partials.product-category-item')
					->with('children',$child_cats)
					->with('has_description', $has_description)
					->with('level', $level)
					->with('level_name', $level_name),
				'level' => $level
			));
		}
	}

	public function getCategoryChildrenLevel2(Request $request) {
        $show_empty_cats = $this->settings->get('show_empty_cats');
		if ($request->ajax()) {
			$cat        = Input::get('cat_id');
            $purchased_plan_uuid = Input::get('uuid'); $plan = null; $purchase_plan = null;
            if ($purchased_plan_uuid) {
                $user_id = $request->user()->id;
                $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $purchased_plan_uuid)->orderBy('id', 'desc')->firstOrFail();
                $plan = $purchase_plan->plan;
            }

			$category = RecurlyCategories::findorfail($cat);
			$children = $category->getChildrenCategories()->where('trial', 0)->get(); $counts = [];

			foreach($children as $id=>$child) {
				$child_cats_list = $child->getChildrenList();
                $child_cats_list[] = $child->id;
                if (!$show_empty_cats) {
                    $prod_count_query = RecurlyProducts::whereIn('category_id', $child_cats_list)->where('plan_availability','in_stock')->whereNotIn('billing_type',['trial']);
                    if ($plan) {
                        if ($plan->plan_type == 'vpn_dedicated') {
                            $prod_count_query->where('plan_type', 'vpn_dedicated');
                            $prod_count_query->where('city', $plan->city);
                            $prod_count_query->where('vpn_users', '>', $plan->vpn_users);
                            $prod_count_query = $prod_count_query->where('price', '>=', $plan->price);
                            $type = 'vpn';
                        } else {
							$prod_count_query = $prod_count_query->where('price', '>', $plan->price);
                            $prod_count_query->whereIn('plan_type', ['simple', 'complex', 'dedicated']);
                            $type = 'proxy';
                        }
                    }
                    $prod_count = $prod_count_query->count();
                } else $prod_count = 1;
				$counts[$child->id] = $prod_count;
			}
			$child_cats = array(); $has_description = FALSE;
			foreach ($children as $child) {
				if (isset($counts[$child->id]) && $counts[$child->id] > 0) {
					$myobj = new \stdClass();
					$myobj->id = $child->id;
					$myobj->name = $child->name;
                    if (!empty($child->description)) $has_description = TRUE;
					$myobj->description = $child->description;
					if ($child->getChildrenCategories->count()) {
						$myobj->hasChild = true;
					} else $myobj->hasChild = false;

					array_push($child_cats, $myobj);
				}
			}

			return response()->json(array(
				'radio_list' => (String)view('recurly.partial-product-radio')
					->with('children',$child_cats)->with('has_description', $has_description),
			));
		}

	}

	public function getCategoryChildrenLevel3(Request $request) {
        $show_empty_cats = $this->settings->get('show_empty_cats');
		if ($request->ajax()) {
			$cat        = Input::get('cat_id');
            $purchased_plan_uuid = Input::get('uuid'); $plan = null; $purchase_plan = null;
            if ($purchased_plan_uuid) {
                $user_id = $request->user()->id;
                $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $purchased_plan_uuid)->orderBy('id', 'desc')->firstOrFail();
                $plan = $purchase_plan->plan;
            }

			$category = RecurlyCategories::findorfail($cat);
			$children = $category->getChildrenCategories()->where('trial', 0)->get(); $counts = [];
			foreach($children as $id=>$child) {
				$child_cats_list = $child->getChildrenList();
                $child_cats_list[] = $child->id;
                if (!$show_empty_cats) {
                    $prod_count_query = RecurlyProducts::whereIn('category_id', $child_cats_list)->where('plan_availability','in_stock')->whereNotIn('billing_type',['trial']);
                    if ($plan) {
                        if ($plan->plan_type == 'vpn_dedicated') {
                            $prod_count_query->where('plan_type', 'vpn_dedicated');
                            $prod_count_query->where('city', $plan->city);
                            $prod_count_query->where('vpn_users', '>', $plan->vpn_users);
                            $prod_count_query->where('price', '>=', $plan->price);
                            $type = 'vpn';
                        } else {
							$prod_count_query = $prod_count_query->where('price', '>', $plan->price);
                            $prod_count_query->whereIn('plan_type', ['simple', 'complex', 'dedicated']);
                            $type = 'proxy';
                        }
                    }
                    $prod_count = $prod_count_query->count();
                } else $prod_count = 1;
				$counts[$child->id] = $prod_count;
			}
			$child_cats = array(); $has_description = FALSE;
			foreach ($children as $child) {
				if (isset($counts[$child->id]) && $counts[$child->id] > 0) {
					$myobj = new \stdClass();
					$myobj->id = $child->id;
					$myobj->name = $child->name;
                    if (!empty($child->description)) $has_description = TRUE;
					$myobj->description = $child->description;
					if ($child->getChildrenCategories->count()) {
						$myobj->hasChild = true;
					} else $myobj->hasChild = false;
					array_push($child_cats, $myobj);
				}
			}

			return response()->json(array(
				'radio_list' => (String)view('recurly.partial-product-features-radio')
					->with('children',$child_cats)->with('has_description', $has_description),
			));
		}

	}

	public function getUnitAndLocationSelect(Request $request){
		$category_id = $request->get('cat_id');
        $purchased_plan_uuid = Input::get('uuid'); $plan = null; $purchase_plan = null;
        if ($purchased_plan_uuid) {
            $user_id = $request->user()->id;
            $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $purchased_plan_uuid)->orderBy('id', 'desc')->firstOrFail();
            $plan = $purchase_plan->plan;
        }

		$proxy_units_query = RecurlyProducts::where('category_id', $category_id)->where('billing_type','duration')
			->where('plan_type', '!=', 'vpn_dedicated');
        if ($plan) {
            if ($plan->plan_type == 'vpn_dedicated') {
                $proxy_units_query->where('plan_type', 'vpn_dedicated');
                $proxy_units_query->where('city', $plan->city);
                $proxy_units_query->where('vpn_users', '>', $plan->vpn_users);
                $proxy_units_query->where('price', '>=', $plan->price);
                $type = 'vpn';
            } else {
				$proxy_units_query = $proxy_units_query->where('price', '>', $plan->price);
                $proxy_units_query->whereIn('plan_type', ['simple', 'complex', 'dedicated']);
                $type = 'proxy';
            }
        }

        $proxy_units = $proxy_units_query->lists('plan_quantity')->unique()->sort();
        if ($category_id == 120 || $category_id == 122) {
			$products_locations = RecurlyProducts::lists('location')
				->unique()->flatten()->reject(function ($value) {
					return $value == 11;
				})->reject(function ($value) {
					return $value == '';
				})->toArray();
			$locations = array_only(Regions::getActivated(), $products_locations);
		} else {
			$locations = '';
		}
		return response()->json(array(
			'selects' => (String)view('recurly.partials.unit_select',
				compact('proxy_units', 'locations'))
		));
	}

	public function getProductsFiltered(Request $request) {

		if ($request->ajax()) {
            $purchased_plan_uuid = Input::get('uuid'); $plan = null; $purchase_plan = null;
            if ($purchased_plan_uuid) {
                $user_id = $request->user()->id;
                $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $purchased_plan_uuid)->orderBy('id', 'desc')->firstOrFail();
                $plan = $purchase_plan->plan;
            }

			if ($request->user()) {
				$user_balance = $this->recurlyService->getAccountBalance($request->user());
			} else {
				$user_balance = 0;
			}

			$cat_id = Input::get('cat_id');
			$products = RecurlyProducts::where('category_id',$cat_id)->where('plan_availability','in_stock')->whereNotIn('billing_type',['trial']);
			if($request->has('unit') && $request->get('unit') != ''){
				$products = $products->where('plan_quantity',$request->get('unit'));
			} else {
				$products = $products->where('plan_quantity', 1);
			}
			if($request->has('location') && $request->get('location') != ''){
				$products = $products->where('location',$request->get('location'));
			}

            if ($plan) {
                if ($plan->plan_type == 'vpn_dedicated') {
                    $products->where('plan_type', 'vpn_dedicated');
                    $products->where('city', $plan->city);
                    $products->where('vpn_users', '>', $plan->vpn_users);
                    $products->where('price', '>=', $plan->price);
                    $type = 'vpn';
                } else {
					$products = $products->where('price', '>', $plan->price);
                    $products->whereIn('plan_type', ['simple', 'complex', 'dedicated']);
                    $type = 'proxy';
                }
            }

			$package_products = clone $products;
			$package_products->where('plan_type', 'package');
			$package_products = $package_products->get();

			$products->where('plan_type', '!=', 'package');
			$products = $products->get();

			if(count($products) + count($package_products) == 0) {
				return response()->json([
				    'product_list' => "<h3>No Products Found</h3>",
			    ]);

			}

            $discount = [];
			foreach($package_products as $product) {
				if (!isset($discount[$product->category_id])) {
					$discount_settings = $this->settings->get('discounts_'.$product->category_id);
					if ($discount_settings) {
						$discount_settings = unserialize($discount_settings);
						$discount[$product->category_id] = $discount_settings;
					}
				}
			}
            foreach($products as $product) {
                if (!isset($discount[$product->category_id])) {
                    $discount_settings = $this->settings->get('discounts_'.$product->category_id);
                    if ($discount_settings) {
                        $discount_settings = unserialize($discount_settings);
                        $discount[$product->category_id] = $discount_settings;
                    }
                }
            }

            if ($plan) {
                $unused_funds = $this->recurlyService->getUnusedFinds($purchased_plan_uuid);
                return response()->json(array(
                    'product_list' => (String)view('recurly.partial-product-upgrade-filtered')
                        ->with('products', $products)->with('plan', $plan)->with('purchase_plan', $purchase_plan)
                        ->with('unused_funds', $unused_funds)->with('user_balance', $user_balance)->with('discount', $discount)
                ));
            } else {
                return response()->json(array(
                    'product_list' => (String)view('recurly.partial-product-filtered-complex', [
						'products' => $products,
						'package_products' => $package_products,
						'discount' => $discount,
						'user_balance' => $user_balance,
					])
                ));
            }
		}

	}

    public function getLogin(Request $request) {
        Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');
        try {
            $account = new \Recurly_Account();
            $account_info = $account->get($request->user()->user_identifier);
            $login_token = $account_info->hosted_login_token;
            return redirect()->away(env('RECURLY_BASEURL') . '/account/' . $login_token);
        }
        catch (\Recurly_ValidationError $e) {
            echo "<pre>"; print_r($e->getMessage()); echo "</pre>";die();
        }
    }

    public function getIframe(Request $request) {
        Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');
        try {
            $account = new \Recurly_Account();
            $account_info = $account->get($request->user()->user_identifier);
            $login_token = $account_info->hosted_login_token;
            return view('recurly.recurly_iframe', [
                'url' => env('RECURLY_BASEURL') . '/account/' . $login_token
            ]);
        }
        catch (\Recurly_ValidationError $e) {
            echo "<pre>"; print_r($e->getMessage()); echo "</pre>";die();
        }
    }

	public function recurlyProductUpgrade($uuid, $plan_id, Request $request) {
		$past_due = $this->recurlyService->getPostDueAmount($request->user());
		if ($past_due > 0) {
			return redirect()->action('BillingController@getInvoices')->with('action', 'past_due');
		}

		$user_balance = $this->recurlyService->getAccountBalance($request->user());
		$unused_funds = $this->recurlyService->getUnusedFinds($uuid);

		$user_id = $request->user()->id;
		$purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
		$plan = $purchase_plan->plan;

		if ($plan->plan_type == 'router' || $plan->plan_type == 'package') {
			return redirect()->action('RecurlyController@productDetailForCustomers', [$purchase_plan->uuid, $plan_id])->with('error', 'Upgrade not allowed for this plan.');
		}

        $durations = recurly_plan_duration();
        foreach($durations as $key=>$text) {
            unset($durations[$key]);
            if ($plan->duration == $key) break;
        }

        if ($plan->plan_type == 'vpn_dedicated') {
            $type = 'vpn';
        } else {
            $type = 'proxy';
        }

        $countries = \CountryState::getCountries();
        $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
        $account = $this->recurlyService->getAccount(Auth::user());

        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
        $billing_descriptor = $this->settings->get('billing_descriptor');

		if ($type == 'vpn') {
			$category = RecurlyCategories::where('id', 42)->first();
		} else {
			$category = RecurlyCategories::where('id', 1)->first();
		}

        $viewData = [
            'purchase_plan' => $purchase_plan,
            'plan' => $plan,
            'countries' => $countries,
            'billing_info' => $billing_info,
            'account' => $account,
            'states' => $states,
            'billing_descriptor' => $billing_descriptor,
            'type' => $type,
            'unused_funds' => $unused_funds,
            'user_balance' => $user_balance,
            'category' => $category,
			'enable_vat' => $this->settings->get('enable_vat'),
			'terms' => $this->settings->get('terms'),
        ];


		return view('recurly.recurly_product_upgrade', $viewData);
	}

	public function getFeed() {
        $categories = RecurlyCategories::with('parent.parent.parent.parent')->where('trial', 0)->get();
        $qty = RecurlyProducts::select('recurly_products.anytime_ports')->where('billing_type', 'duration')->where('plan_type', '!=', 'vpn_dedicated')->groupBy('anytime_ports')->lists('anytime_ports', 'anytime_ports');
        $vpn_users = RecurlyProducts::select('recurly_products.vpn_users')->where('billing_type', 'duration')->where('plan_type', 'vpn_dedicated')->groupBy('vpn_users')->lists('vpn_users', 'vpn_users');

        $products = RecurlyProducts::where('billing_type', 'duration')
			->orderBy('anytime_ports', 'ASC')
            ->orderBy('vpn_users', 'ASC')
            ->orderBy('price', 'ASC')
            ->get();
        $regions = Regions::orderBy('rid', 'ASC')->lists('name', 'rid');
        $cities = City::where('cid', '!=', 1)->lists('name', 'cid');
        $discount_settings = SettingsModel::where('name', 'LIKE', 'discounts_%')->get();
        $discounts = [];
        foreach($discount_settings as $discount_setting) {
            $name = explode('_', $discount_setting->name);
            if (isset($name[1]) && is_numeric($name[1])) {
                $discounts[$name[1]] = unserialize($discount_setting->value);
                $discounts[$name[1]]['cat_id'] = $name[1];
            }
        }

        $feed = [];
        $feed['regions'] = $regions;
        $feed['cities'] = $cities;
        $feed['qty'] = $qty;
        $feed['vpn_users'] = $vpn_users;
        foreach($categories as $category) {
            $mainParent = $category->mainParent();
            $feed[$category->id] = [];
            $feed[$category->id]['category_name'] = $category->name;
            $tree = $category->categoryTree();
            $pos = strpos($tree, '>');
            $tree = substr($tree, $pos+1);
            $feed[$category->id]['category_tree'] = $tree;
            $feed[$category->id]['description'] = $category->description;
            $feed[$category->id]['main_parent'] = $mainParent ? $mainParent->id : 0;
            $feed[$category->id]['products'] = [];
            if (isset($discounts[$category->id])) $feed[$category->id]['is_discount'] = TRUE;
                else $feed[$category->id]['is_discount'] = FALSE;
            $feed[$category->id]['qty'] = [];
            $feed[$category->id]['switch_type'] = [];
        }
        foreach($products as $product) {
			$switch_type = [];
			if ($product->plan_type == 'dedicated') $switch_type = ['basic', 'turbo'];
				elseif ($product->switch_type) $switch_type = [$product->switch_type];
            $feed[$product->category_id]['products'][$product->id] = [
                'plan_id' => $product->id,
                'plan_code' => $product->plan_code,
                'plan_name' => $product->plan_name,
                'plan_description' => $product->plan_description,
                'plan_price' => $product->price,
                'price_by_month' => $product->getPriceByMonth(),
                'plan_type' => $product->plan_type,
				'switch_type' => $switch_type,
                'api_type' => $product->type,
                'duration' => $product->getDurationText(),
                'billed_at' => $product->getDurationText(TRUE),
                'qty' => $product->anytime_ports,
                'unit_of_measure' => $product->unit_of_measure,
                'availability' => $product->plan_availability,
                'location' => $product->location,
                'city' => $product->city,
                'region_changeable' => $product->region_changeable,
                'vpn_users' => $product->vpn_users,
            ];
            $feed[$product->category_id]['region'] = $product->location;
            $feed[$product->category_id]['city'] = $product->city;
            if ($product->plan_type == 'simple' && $product->type == 'dedicated') $product->plan_type = 'dedicated';
            $feed[$product->category_id]['plan_type'] = $product->plan_type;
            if ($switch_type) $feed[$product->category_id]['switch_type'] = $switch_type;
            $feed[$product->category_id]['geo'] = $product->region_changeable ? 'geo' : 'nogeo';
            $feed[$product->category_id]['qty'][$product->anytime_ports] = $product->anytime_ports;
            $feed[$product->category_id]['vpn_users'] = $product->vpn_users;
        }

		return json_encode($feed);
	}

    /**
     * Create/Edit recurly category validator
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function categoryValidator(array $data, RecurlyCategories $category) {
        $rules = [
            'name' => 'required|string|unique:recurly_categories,name,'.$category->id.',id,parent_category_id,'.$category->parent_category_id,
            'description' => 'string',
            'weight' => 'numeric',
            'trial' => 'boolean',
            'recommended_plan' => 'numeric|exists:recurly_products,id,category_id,'.$category->id
        ];
        return Validator::make($data, $rules);
    }
	
}
