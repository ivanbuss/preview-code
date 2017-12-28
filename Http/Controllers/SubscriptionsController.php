<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\Models\ShippingData;
use App\Models\SubscriptionCancelReason;
use App\Services\RecurlyService;
use App\Services\StorePackageService;
use App\Services\StoreRouterService;
use Illuminate\Http\Request;
use Carbon;
use Illuminate\Support\Facades\Validator;

use App\Services\StoreProxyService;
use App\Services\StoreVPNService;

class SubscriptionsController extends Controller
{

    protected $proxyService;
    protected $vpnService;
    protected $routerService;
    protected $packageService;
    protected $recurlyService;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(StoreProxyService $proxyService, StoreVPNService $vpnService, StoreRouterService $routerService, StorePackageService $packageService, RecurlyService $recurlyService) {
        $this->middleware('auth');
        $this->proxyService = $proxyService;
        $this->vpnService = $vpnService;
        $this->routerService = $routerService;
        $this->packageService = $packageService;
        $this->recurlyService = $recurlyService;
    }

    /**
     * Show the admin settings page.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSubscriptions() {
        $statuses = [
            PurchasePlans::ACTIVE => 'Active',
            PurchasePlans::CANCELED => 'Canceled',
            PurchasePlans::PROVISIONING => 'Provisioning',
            PurchasePlans::EXPIRED => 'Expired',
        ];
        $local_statuses = [
            'enabled' => 'Enabled',
            PurchasePlans::PROVISIONING => 'Provisioning',
            'disabled' => 'Disabled',
            'deleted' => 'Deleted',
            PurchasePlans::ERROR => 'Error',
        ];
        $shipping_statuses = [
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'returned' => 'Returned',
            'received' => 'Received'
        ];
        $current_month_start = Carbon\Carbon::now()->startOfMonth();
        $current_month_end = Carbon\Carbon::now()->endOfMonth();

        return view('recurly.admin.subscriptions.list', [
            'statuses' => $statuses,
            'local_statuses' => $local_statuses,
            'shipping_statuses' => $shipping_statuses,
            'current_month_start' => $current_month_start,
            'current_month_end' => $current_month_end
        ]);
    }

    public function getSubscriptionsTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'email'],
            2 => ['name'=>'ip_address'],
            3 => ['name'=>'uuid'],
            5 => ['name'=>'status'],
            6 => ['name'=>'shipping_status'],
            7 => ['name'=>'plan_name'],
            8 => ['name'=>'plan_code'],
            9 => ['name'=>'purchase_date'],
            10 => ['name'=>'expiration_date'],
        ];

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = PurchasePlans::select('customer_purchase_plans.*', 'users.email AS email',
            'recurly_products.plan_name AS plan_name', 'recurly_products.plan_code AS plan_code', 'shipping_data.status AS shipping_status')
            ->join('users', 'customer_purchase_plans.customer_id', '=', 'users.id')
            ->leftjoin('recurly_products', 'customer_purchase_plans.plan_id', '=', 'recurly_products.id')
            ->leftjoin('shipping_data', 'customer_purchase_plans.uuid', '=', 'shipping_data.uuid');

        $recordsTotal = $query->count();

        $date_from = $request->get('filterDateFrom') ? $request->get('filterDateFrom') : null;
        if ($date_from) {
            $date_from_time = strtotime($date_from); $date_from_carbon = Carbon\Carbon::createFromTimestamp($date_from_time);
            $query->where('customer_purchase_plans.created_at', '>=', $date_from_carbon);
        }
        $date_to = $request->get('filterDateTo') ? $request->get('filterDateTo') : null;
        if ($date_to) {
            $date_from_to = strtotime($date_to); $date_to_carbon = Carbon\Carbon::createFromTimestamp($date_from_to);
            $query->where('customer_purchase_plans.created_at', '<=', $date_to_carbon);
        }
        $service_status = $request->get('filterServiceStatus') ? $request->get('filterServiceStatus') : null;
        if ($service_status) {
            $query->where('customer_purchase_plans.local_status', $service_status);
        }
        $subscription_status = $request->get('filterSubscriptionStatus') ? $request->get('filterSubscriptionStatus') : null;
        if ($subscription_status) {
            $query->where('customer_purchase_plans.status', $subscription_status);
        }
        $shipping_status = $request->get('filterShippingStatus') ? $request->get('filterShippingStatus') : null;
        if ($shipping_status) {
            $query->where('shipping_data.status', $shipping_status);
        }

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('recurly_products.plan_code', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('recurly_products.plan_name', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('users.email', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('customer_purchase_plans.uuid', 'LIKE', '%'.$search['value'].'%');
            });
        }
        $recordsFiltered = $query->count();

        $orders = $request->get('order') ? $request->get('order') : [];
        $this->dataTableSorting($query, $columns, $orders);

        $length = $request->get('length') ? $request->get('length') : 10;
        $start = $request->get('start') ? $request->get('start') : 0;
        $draw = $request->get('draw') ? $request->get('draw') : 1;

        if ($length != -1) {
            $query->offset($start)->limit($length);
        }
        $collective = $query->get();

        $statuses = [
            PurchasePlans::ACTIVE => 'Active',
            PurchasePlans::CANCELED => 'Canceled',
            PurchasePlans::PROVISIONING => 'Provisioning',
            PurchasePlans::EXPIRED => 'Expired',
        ];
        $local_statuses = [
            '' => '',
            'enabled' => 'Enabled',
            PurchasePlans::PROVISIONING => 'Provisioning',
            'disabled' => 'Disabled',
            'deleted' => 'Deleted',
            PurchasePlans::ERROR => 'Error',
        ];
        $shipping_statuses = [
            '' => '',
            'processing' => 'Processing',
            'shipped' => 'Shipped',
            'returned' => 'Returned',
            'received' => 'Received'
        ];

        $items = [];
        foreach($collective as $item) {
            $action = '';
            if ($item->status == PurchasePlans::PROVISIONING || $item->local_status == PurchasePlans::PROVISIONING || $item->local_status == PurchasePlans::ERROR) {
                $action = view('recurly.admin.subscriptions.activate_form', ['subscription'=>$item])->render();
            }/* else {
                $action = view('recurly.admin.subscriptions.enable-checkbox', ['subscription'=>$item])->render();
            }*/
            $items[] = [
                $item->id,
                $item->email,
                $item->ip_address,
                $item->uuid,
                $local_statuses[$item->local_status],
                $statuses[$item->status],
                $shipping_statuses[$item->shipping_status],
                $item->plan_name,
                $item->plan_code,
                $item->purchase_date->format('F jS Y g:i A'),
                $item->expiration_date? $item->expiration_date->format('F jS Y g:i A') : null,
                '<a class="btn btn-default btn-sm" href="'.action('SubscriptionsController@getSubscriptionView', $item->id).'"><span class="glyphicon glyphicon-edit"></span></a>',
                $action
            ];
        }
        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

    public function getSubscriptionView(PurchasePlans $subscription) {
        $coupon = null;
        if ($subscription->coupon_code) {
            $coupon = $this->recurlyService->getCoupon($subscription->coupon_code);
        }
        $shippingData = ShippingData::where('uuid', $subscription->uuid)->where('customer_id', $subscription->customer_id)->first();
        $countries = \CountryState::getCountries();
        $states = \CountryState::getStates($shippingData ? $shippingData->country : 'US');
        $plan = $subscription->plan;

        $cancel_reason = $subscription->cancel_reason;
        return view('recurly.admin.subscriptions.view', [
            'subscription'=>$subscription,
            'coupon'=>$coupon,
            'countries' => $countries,
            'states' => $states,
            'shippingData' => $shippingData,
            'plan' => $plan,
            'cancel_reason' => $cancel_reason,
        ]);
    }

    public function postSubscriptionActivate(PurchasePlans $subscription, Request $request) {
        $plan = $subscription->plan;
        $user = $subscription->customer;
        try {
            if ($plan->plan_type == 'vpn_dedicated') {
                $response = $this->vpnService->createPurchaseVPNServer($subscription, $plan, $user);
            } else if ($plan->plan_type == 'router') {
                $response = $this->routerService->createPurchaseRouterService($subscription, $plan, $user);
            } else {
                $response = $this->proxyService->createPurchasedProxyService($subscription, $plan, $user);
            }
            if ($response['success'] == FALSE) {
                return redirect()->action('SubscriptionsController@getSubscriptions')->with('error', $response['error']);
            }
            if (isset($response['message']) && !empty($response['message'])) {
                return redirect()->action('SubscriptionsController@getSubscriptions')->with('success', 'Subscription activated and ' . $response['message']);
            } else {
                return redirect()->action('SubscriptionsController@getSubscriptions')->with('success', 'Subscription activated');
            }
        } catch(\Exception $e) {
            return redirect()->action('SubscriptionsController@getSubscriptions')->with('error', $e->getMessage());
        }
    }

    public function postSubscriptionEnable(PurchasePlans $subscription, Request $request) {
        if ($request->get('status')) {
            if ($subscription && $subscription->local_status != 'enabled' && $subscription->local_status != PurchasePlans::PROVISIONING && $subscription->status != PurchasePlans::PROVISIONING) {
                $plan = $subscription->plan;
                $accountObj = $subscription->customer;
                if ($plan && $plan->plan_type == 'package') {
                    $this->packageService->enableService($subscription, $accountObj);
                } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->enableVPNServer($subscription, $accountObj);
                } else if ($plan && $plan->plan_type == 'router') {
                    $this->routerService->enableRouter($subscription, $accountObj);
                } else {
                    $this->proxyService->enableProxy($subscription, $accountObj);
                }
                $subscription->local_status = 'enabled';
                $subscription->save();
                return redirect()->back()->with('success', 'Subscription has been enabled successfully.');
            }
        } else {
            if ($subscription && $subscription->local_status != 'disabled' && $subscription->local_status != PurchasePlans::PROVISIONING && $subscription->status != PurchasePlans::PROVISIONING) {
                $plan = $subscription->plan;
                $accountObj = $subscription->customer;
                if ($plan && $plan->plan_type == 'package') {
                    $this->packageService->disableService($subscription, $accountObj);
                } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->disableVPNServer($subscription, $accountObj);
                } else if ($plan && $plan->plan_type == 'router') {
                    $this->routerService->disableRouter($subscription, $accountObj);
                } else {
                    $this->proxyService->disableProxy($subscription, $accountObj);
                }
                $subscription->local_status = 'disabled';
                $subscription->save();
                return redirect()->back()->with('success', 'Subscription has been disabled successfully.');
            }
        }
        return redirect()->back()->with('error', 'Error has occurred.');
    }

    public function postUpdateShippingData(PurchasePlans $subscription, Request $request) {
        $shippingData = ShippingData::where('uuid', $subscription->uuid)->where('customer_id', $subscription->customer_id)->firstOrFail();

        $validator = $this->shippingValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $shippingData->first_name = $request->get('shipping_first_name');
        $shippingData->last_name = $request->get('shipping_last_name');
        $shippingData->address = $request->get('shipping_address1');
        $shippingData->city = $request->get('shipping_city');
        $shippingData->country = $request->get('shipping_country');
        $shippingData->postal_code = $request->get('shipping_postal_code');
        $shippingData->state = $request->get('shipping_state');
        $shippingData->phone = $request->get('shipping_phone');
        $shippingData->activate = $request->get('activation_rule') ? FALSE : TRUE;
        $shippingData->save();

        return redirect()->back()->with('success', 'Shipping data have been changed.');
    }

    public function postUpdateShippingStatus(PurchasePlans $subscription, Request $request) {
        $shippingData = ShippingData::where('uuid', $subscription->uuid)->where('customer_id', $subscription->customer_id)->firstOrFail();

        $validator = $this->shippingStatusValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        if ($shippingData->shipper != $request->get('shipper')) {
            $shippingData->shipper = $request->get('shipper');
            $shippingData->shipper_updated_at = Carbon\Carbon::now();
        }
        $shippingData->tracking_number = $request->get('shipping_tracking_number');
        if ($shippingData->status != $request->get('shipping_status')) {
            $shippingData->status = $request->get('shipping_status');
            $shippingData->status_updated_at = Carbon\Carbon::now();
        }
        $shippingData->save();

        return redirect()->back()->with('success', 'Shipping data have been changed.');
    }

    protected function shippingValidator($data = []) {
        $rules = [
            'shipping_first_name' => 'required|max:255',
            'shipping_last_name' => 'required|max:255',
            'shipping_address1' => 'required|max:255',
            'shipping_city' => 'required|max:255',
            'shipping_country' => 'required|max:255',
            'shipping_postal_code' => 'required|max:255',
            'shipping_state' => 'max:255',
            'shipping_phone' => 'required|max:255',
            'activation_rule' => 'boolean'
        ];
        return Validator::make($data, $rules);
    }

    protected function shippingStatusValidator($data = []) {
        $rules = [
            'shipper' => 'required|in:ups,fedex,dhl,usps,amazon,ems,royal_mail',
            'shipping_tracking_number' => 'required|max:255',
            'shipping_status' => 'required|in:processing,shipped,returned,received',
        ];
        return Validator::make($data, $rules);
    }

}
