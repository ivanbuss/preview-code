<?php

namespace App\Http\Controllers;

use App\Models\CustomerVPNData;
use App\Models\CustomerVPNUsers;
use App\Models\ShippingData;
use App\Models\Transaction;
use App\Services\Settings;
use App\Services\StorePackageService;
use App\Services\StoreProxyService;
use App\Services\StoreRouterService;
use App\Services\StoreVPNService;
use App\Services\UserService;
use App\User;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Redirect;
use Carbon\Carbon;
use App\Services\RecurlyService;

use App\Models\CustomerProxyData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;


use Illuminate\Support\Facades\Validator;
use Recurly_Account;
use Recurly_Client;
use Recurly_Subscription;
use Recurly_BillingInfo;
use Recurly_ValidationError;


class RecurlyBuyformController extends Controller
{

    protected $recurlyService;
    protected $proxyService;
    protected $vpnService;
    protected $routerService;
    protected $packageService;
    protected $userService;
    protected $settings;

    function __construct(RecurlyService $recurlyService, StoreProxyService $proxyService, StoreVPNService $vpnService, StoreRouterService $routerService, StorePackageService $packageService, UserService $userService, Settings $settings) {
        $this->recurlyService = $recurlyService;
        $this->proxyService = $proxyService;
        $this->vpnService = $vpnService;
        $this->routerService = $routerService;
        $this->packageService = $packageService;
        $this->userService = $userService;
        $this->settings = $settings;
    }

    public function getRecurlyPaymentModal($plan_code) {
        $plan = RecurlyProducts::where('plan_code', $plan_code)->first();
        if (!$plan) {
            return response()->json(['success'=>false, 'error'=>'Plan not found']);
        }

        if (Auth::user()) {
            $user_balance = $this->recurlyService->getAccountBalance(Auth::user());
            $past_due = $this->recurlyService->getPostDueAmount(Auth::user());
            $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
            $account = $this->recurlyService->getAccount(Auth::user());
        } else {
            $user_balance = 0; $past_due = 0;
            $billing_info = null; $account = null;
        }

        $amount_due = $plan->price + ($past_due/100); $plan_price = $plan->price;
        if ($plan->setup_fee > 0) {
            $amount_due = $amount_due + $plan->setup_fee;
            $plan_price = $plan_price + $plan->setup_fee;
        }
        $credit_value = ($user_balance + $past_due) / 100;
        $credit_used = ((($past_due / 100) + ($plan->price + $plan->setup_fee)) > $credit_value) ? $credit_value : (($past_due / 100) + $plan->price + $plan->setup_fee);
        if ($credit_used < 0) $credit_used = 0;
        if ($credit_used > 0) $amount_due = $amount_due - $credit_used;
        $amount_due = ($amount_due < 0) ? 0 : $amount_due;
        $countries = \CountryState::getCountries();
        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');
        $plan_type = $plan->getPlanType();
        if ($plan_type == 'package' && $plan->isRouterPackage()) $plan_type = 'router';
        $data = [
          'billing_info' => $billing_info,
          'account' => $account,
          'countries' => $countries,
          'states' => $states,
          'enable_vat' => $this->settings->get('enable_vat'),
          'billing_descriptor' => $this->settings->get('billing_descriptor'),
          'plan' => $plan,
          'plan_type' => $plan_type,
          'amount_due' => ($amount_due < 0) ? 0 : number_format($amount_due, 2, '.', ''),
          'plan_price' => $plan_price,
          'credit_value' => $credit_value,
          'credit_used' => $credit_used,
          'isGuest' => Auth::guest(),
        ];

        $return = [
          'success'=>true,
          'form'=>view('recurly.partials.recurly_payment_modal', $data)->render()
        ];
        return response()->json($return);
    }

    public function postRecurlyBuyForm(Request $request) {
        $plan_code = $request->get('recurly_plan_code');
        $plan = RecurlyProducts::where('plan_code', $plan_code)->first();
        if (!$plan) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', 'Selected plan not found');
        }
        $plan_type = $plan->getPlanType();
        if ($plan_type == 'package' && $plan->isRouterPackage()) $plan_type = 'router';

        $validator = Validator::make($request->all(), ['phone'=>'required']);
        $coupon_code = $request->get('coupon_code');
        if ($validator->fails()) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', 'Phone field is required');
        }

        if ($plan_type == 'router') {
            $shippingValidator = $this->shippingDataValidator($request->all());
            if ($shippingValidator->fails()) {
                $this->throwValidationException(
                    $request, $shippingValidator
                );
            }
        }

        try {
            $account = $this->recurlyService->getAccount(Auth::user());
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', $e->getMessage());
        }

        $subscriptionResponce = $this->recurlyService->createSubscription($request->get('recurly_plan_code'), $account, $request->all(), null, $coupon_code);
        if (!$subscriptionResponce['success']) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->withErrors([
                'error' => 'Error: '.$subscriptionResponce['error'],
            ]);
        }
        $this->recurlyService->cleanCache($request->user());
        $subscription = $subscriptionResponce['subscription'];
        return $this->saveRecurly($plan, $subscription, $request->all(), $request->getClientIps());
    }

    public function postRecurlyBuyGuestForm(Request $request) {
        $plan_code = $request->get('recurly_plan_code');
        $plan = RecurlyProducts::where('plan_code', $plan_code)->first();
        if (!$plan) {
            return Redirect()->action('RecurlyController@recurlyProductsNewGuest')->with('error', 'Selected plan not found');
        }
        $plan_type = $plan->getPlanType();
        if ($plan_type == 'package' && $plan->isRouterPackage()) $plan_type = 'router';

        $validator = Validator::make($request->all(), ['phone'=>'required']);
        $coupon_code = $request->get('coupon_code');
        if ($validator->fails()) {
            return Redirect()->action('RecurlyController@recurlyProductsNewGuest')->with('error', 'Phone field is required');
        }

        $data = $request->all();
        $data['first_name'] = $data['first-name'];
        $data['last_name'] = $data['last-name'];

        $accountValidator = $this->userService->registrationValidator($data);
        if ($accountValidator->fails()) {
            $this->throwValidationException(
              $request, $accountValidator
            );
        }

        if ($plan_type == 'router') {
            $shippingValidator = $this->shippingDataValidator($request->all());
            if ($shippingValidator->fails()) {
                $this->throwValidationException(
                  $request, $shippingValidator
                );
            }
        }


        $trial_plans = $this->userService->getTrialPlans();
        $data['trial_plan_code'] = isset($trial_plans['trial_plan_code']) ? $trial_plans['trial_plan_code'] : null;
        $data['free_vpn_plan_code'] = isset($trial_plans['free_vpn_plan_code']) ? $trial_plans['free_vpn_plan_code'] : null;
        $data['trial_vpn_plan_code'] = isset($trial_plans['trial_vpn_plan_code']) ? $trial_plans['trial_vpn_plan_code'] : null;

        $data['ip_address'] = $request->getClientIp();

        $user = $this->userService->create($data, FALSE);
        if (!$user) {
            return redirect()->back()->with('error', 'Failed to create your account please try again later.');
        }

        $user->active = 1;
        $user->activation_code = '';
        if ($user->save()) {
            $this->userService->createRecurlyAccount($user);
            try {
                $account = $this->recurlyService->getAccount($user);
            } catch (\Recurly_NotFoundError $e) {
                return Redirect()->action('RecurlyController@recurlyProductsNewGuest')->with('error', $e->getMessage());
            }

            $subscriptionResponce = $this->recurlyService->createSubscription($request->get('recurly_plan_code'), $account, $request->all(), null, $coupon_code);
            if (!$subscriptionResponce['success']) {
                return Redirect()->action('RecurlyController@recurlyProductsNewGuest')->withErrors([
                  'error' => 'Error: '.$subscriptionResponce['error'],
                ]);
            }

            $this->userService->activateStoreProxyAccount($user);

            $response = $this->vpnService->createVPNService($user);
            if ($response['success'] == FALSE) {
                return redirect()->back()->with('error', $response['error']);
            }

            $random_pass = randomString(10, 'mix');
            $response = $this->vpnService->createVPNUser($user->email, $random_pass, $user, TRUE);
            if ($response['success'] == FALSE) {
                return redirect()->back()->with('error', $response['error']);
            }

            $proxy_trial_response = $this->userService->createProxyTrial($user, $request->getClientIps());
            $free_vpn_response = $this->userService->createFreeVPN($user, $request->getClientIps());
            $trial_vpn_response = $this->userService->createTrialVPN($user, $request->getClientIps());

            Auth::login($user);

            $subscription = $subscriptionResponce['subscription'];
            return $this->saveRecurly($plan, $subscription, $request->all(), $request->getClientIps());
        } else {
            return redirect()->back()->with('error', 'Ooops We could not activate your acount. Try again later.');
        }
    }

    public function postRecurlyBuyFormAPI(Request $request){
        $user= Auth::user();
        $plan_code = Input::get('recurly_plan_code_api');
        $plan = RecurlyProducts::where('plan_code', $plan_code)->first();
        if (!$plan) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', 'Selected plan not found');
        }
        $plan_type = $plan->getPlanType();
        if ($plan_type == 'package' && $plan->isRouterPackage()) $plan_type = 'router';

        $coupon_code = $request->get('coupon_code');

        if ($plan_type == 'router') {
            $shippingValidator = $this->shippingDataValidator($request->all());
            if ($shippingValidator->fails()) {
                $this->throwValidationException(
                    $request, $shippingValidator
                );
            }
        }

        ///fetch recurly customer billing info/////
        try {
            $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', $e->getMessage());
        }

        try {
            $account = \Recurly_Account::get(Auth::user()->user_identifier);
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', $e->getMessage());
        }

        $subscriptionResponce = $this->recurlyService->createSubscription($request->get('recurly_plan_code_api'), $account, null, $billing_info, $coupon_code);
        if (!$subscriptionResponce['success']) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->withErrors([
                'error' => 'Error: '.$subscriptionResponce['error'],
            ]);
        }
        $this->recurlyService->cleanCache($request->user());
        $subscription = $subscriptionResponce['subscription'];
        return $this->saveRecurly($plan, $subscription, $request->all(), $request->getClientIps());
    }

    public function postPayPalRecurlyBuyForm(Request $request){
        dd($request->all());
    }

    public function postRecurlyBuyCreditsForm(Request $request) {
        $plan_code = $request->get('recurly_plan_code_api');
        $plan = RecurlyProducts::where('plan_code', $request->get('recurly_plan_code_api'))->firstOrFail();
        $plan_type = $plan->getPlanType();
        if ($plan_type == 'package' && $plan->isRouterPackage()) $plan_type = 'router';

        $balance = $this->recurlyService->getAccountBalance($request->user());
        $price = $plan->price;
        if ($balance < $price*100) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->withErrors([
                'error' => 'Not enough credits to buy this service',
            ]);
        }

        if ($plan_type == 'router') {
            $shippingValidator = $this->shippingDataValidator($request->all());
            if ($shippingValidator->fails()) {
                $this->throwValidationException(
                    $request, $shippingValidator
                );
            }
        }

        try {
            $account = \Recurly_Account::get($request->user()->user_identifier);
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', $e->getMessage());
        }

        $subscriptionResponce = $this->recurlyService->createManualSubscription($plan_code, $account);
        if (!$subscriptionResponce['success']) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->withErrors([
                'error' => 'Error: '.$subscriptionResponce['error'],
            ]);
        }
        $this->recurlyService->cleanCache($request->user());
        $subscription = $subscriptionResponce['subscription'];
        return $this->saveRecurly($plan, $subscription, $request->all(), $request->getClientIps());
    }

    public function postUpgradeRecurlyBuyForm($uuid, Request $request) {
        $user_id = $request->user()->id;
        $coupon_code = $request->get('coupon_code');

        $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $plan = RecurlyProducts::where('plan_code', $request->get('recurly_plan_code'))->firstOrFail();
        if ($plan->plan_type == 'vpn_dedicated') $type = 'vpn';
            else $type = 'proxy';

        $validator = Validator::make($request->all(), ['phone'=>'required']);
        if ($validator->fails()) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->with('error', 'Phone field is required');
        }

        try {
            $account = $this->recurlyService->getAccount(Auth::user());
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->with('error', $e->getMessage());
        }

        $subscriptionResponce = $this->recurlyService->updateSubscription($purchase_plan->uuid, $plan->plan_code, $account, $request->all(), null, $coupon_code);
        if (!$subscriptionResponce['success']) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->withErrors([
                'error' => 'Error: '.$subscriptionResponce['error'],
            ]);
        }
        $this->recurlyService->cleanCache($request->user());
        $subscription = $subscriptionResponce['subscription'];

        return $this->updateRecurly($purchase_plan, $plan, $subscription, $type);
    }

    public function postUpgradeRecurlyBuyFormAPI($uuid, Request $request) {
        $user_id = $request->user()->id;
        $coupon_code = $request->get('coupon_code');

        $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $plan = RecurlyProducts::where('plan_code', $request->get('recurly_plan_code_api'))->firstOrFail();
        if ($plan->plan_type == 'vpn_dedicated') $type = 'vpn';
            else $type = 'proxy';

        try {
            $billing_info = $this->recurlyService->getBillingInfo(Auth::user());
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->with('error', $e->getMessage());
        }

        try {
            $account = \Recurly_Account::get(Auth::user()->user_identifier);
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->with('error', $e->getMessage());
        }

        $subscriptionResponce = $this->recurlyService->updateSubscription($purchase_plan->uuid, $plan->plan_code, $account, null, $billing_info, $coupon_code);
        if (!$subscriptionResponce['success']) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->withErrors([
                'error' => 'Error: '.$subscriptionResponce['error'],
            ]);
        }
        $this->recurlyService->cleanCache($request->user());
        $subscription = $subscriptionResponce['subscription'];

        return $this->updateRecurly($purchase_plan, $plan, $subscription, $type);
    }

    public function postUpgradeRecurlyBuyCreditsForm($uuid, Request $request) {
        $user_id = $request->user()->id;

        $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $plan = RecurlyProducts::where('plan_code', $request->get('recurly_plan_code_api'))->firstOrFail();
        if ($plan->plan_type == 'vpn_dedicated') $type = 'vpn';
            else $type = 'proxy';

        $balance = $this->recurlyService->getAccountBalance($request->user());
        $unused_funds = $this->recurlyService->getUnusedFinds($uuid);
        $price = $plan->price;

        if (($balance + $unused_funds) < ($price*100)) {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->withErrors([
                'error' => 'Not enough credits to buy this service',
            ]);
        }

        try {
            $account = \Recurly_Account::get(Auth::user()->user_identifier);
        } catch (\Recurly_NotFoundError $e) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->with('error', $e->getMessage());
        }

        $subscriptionResponce = $this->recurlyService->updateManualSubscription($purchase_plan->uuid, $plan->plan_code, $account);
        if (!$subscriptionResponce['success']) {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$uuid, $plan->id])->withErrors([
                'error' => 'Error: '.$subscriptionResponce['error'],
            ]);
        }
        $this->recurlyService->cleanCache($request->user());
        $subscription = $subscriptionResponce['subscription'];

        return $this->updateRecurly($purchase_plan, $plan, $subscription, $type);
    }

    public function postRenewRecurlyBuyCreditsForm(Request $request) {
        $user = $request->user();
        $uuid = $request->get('uuid');
        $account_balance = $this->recurlyService->getAccountBalance($user);
        if ($account_balance >= 0) {
            $invoices = $this->recurlyService->getInvoicesForAccount($user->user_identifier);
            foreach($invoices as $invoice) {
                if ($invoice->state != 'collected' && $invoice->state != 'failed') {
                    $description = 'Charge for invoice ID:'.$invoice->invoice_number;
                    $this->recurlyService->createCharge($invoice->total_in_cents, $description, $user);
                    $this->recurlyService->markInvoiceAsPaid($invoice->invoice_number);
                }
            }
            $this->recurlyService->cleanCache($request->user());
            return redirect()->to('/product-details/'.$uuid)->with('success','Renewed Successfully');
        } else {
            return Redirect('/products?error=error')->withErrors([
                'error' => 'Not enough balance to renew.',
            ]);
        }
    }

    /**
     * @param \App\Models\RecurlyProducts $plan
     * @param $subscription
     * @param $data
     * @param $ip
     * @return mixed
     */
    protected function saveRecurly(RecurlyProducts $plan, $subscription, $data, $ip) {
        $coupon_code = $subscription->coupon_code ? $subscription->coupon_code : null;
        $type = $plan->getPlanType();

        if ($plan) {
            try {
                if ($plan->plan_type == 'package') {
                    $response = $this->packageService->createService($plan, Auth::user(), $subscription->uuid, $ip, $subscription->current_period_ends_at, $coupon_code);
                    if ($response['is_router']) $this->saveShippingData($subscription->uuid, Auth::user(), $data);
                } else if ($type == 'vpn_dedicated') {
                    $response = $this->vpnService->createVPNServer($plan, Auth::user(), $subscription->uuid, $ip, $subscription->current_period_ends_at, $coupon_code);
                    // Assign main VPN user to new VPN service
                    $vpnData = CustomerVPNData::where('uuid', $subscription->uuid)
                      ->where('customer_id', Auth::user()->id)
                      ->where('plan_id', $plan->id)
                      ->first();
                    $vpn_user = CustomerVPNUsers::where('customer_id', Auth::user()->id)->where('is_main', 1)->where('enabled', 1)->first();
                    if ($vpnData && $vpn_user) {
                        $vpn_user_response = $this->vpnService->assignUser($vpn_user, $vpnData, Auth::user());
                    }
                } else if ($type == 'router') {
                    $this->saveShippingData($subscription->uuid, Auth::user(), $data);
                    $response = $this->routerService->queueRouterService($plan, Auth::user(), $subscription->uuid, $ip, $subscription->current_period_ends_at, $coupon_code);
                } else {
                    $response = $this->proxyService->createProxyService($plan, Auth::user(), $subscription->uuid, $ip, $subscription->current_period_ends_at, $coupon_code);
                }
                if (!$response['success'] || $response['success'] == FALSE) {
                    return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', $response['error']);
                }
            } catch(\Exception $e) {
                return Redirect()->action('RecurlyController@recurlyProductsNew')->with('error', $e->getMessage());
            }
            $purchasePlans = $response['purchase_plan'];
            $this->saveSubscription($subscription, $purchasePlans->id);

            if (isset($response['message']) && !empty($response['message'])) {
                return redirect('thankyou')->with('success', 'Subscriptions successfull and ' . $response['message']);
            } else {
                return redirect('thankyou')->with('success', 'Subscriptions successfull');
            }
        } else {
            return Redirect()->action('RecurlyController@recurlyProductsNew')->withErrors([
                'error' => 'Invalid Plan, Subscription, Account, or BillingInfo data.',
            ]);
        }
    }

    protected function updateRecurly(PurchasePlans $purchasePlan, RecurlyProducts $plan, $subscription, $type = 'proxy') {
        $plan = RecurlyProducts::where('plan_code', $plan->plan_code)->first();
        $coupon_code = $subscription->coupon_code ? $subscription->coupon_code : null;
        if ($plan) {
            try {
                if ($type == 'vpn') {
                    $vpnData = CustomerVPNData::where('uuid', $purchasePlan->uuid)
                        ->where('customer_id', Auth::user()->id)
                        ->first();
                    $response = $this->vpnService->upgradeServer($vpnData, $plan, Auth::user());
                    if (!$response['success'] || $response['success'] == FALSE) {
                        return redirect()->action('RecurlyController@recurlyProductUpgrade', [$purchasePlan->uuid, $plan->id])->with('error', $response['error']);
                    }
                } else {
                    $response = $this->proxyService->upgradeProxyService($purchasePlan, $plan, Auth::user());
                    if (!$response['success'] || $response['success'] == FALSE) {
                        return redirect()->action('RecurlyController@recurlyProductUpgrade', [$purchasePlan->uuid, $plan->id])->with('error', $response['error']);
                    }
                }

                if (!$response['success'] || $response['success'] == FALSE) {
                    return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$purchasePlan->uuid, $plan->id])->with('error', $response['error']);
                }
                $purchasePlan->plan_id = $plan->id;
                $purchasePlan->category_id = $plan->category_id;
                if ($coupon_code) $purchasePlan->coupon_code = $coupon_code;

                $purchasePlan->expiration_date = $subscription->current_period_ends_at;
                $purchasePlan->save();
            } catch(\Exception $e) {
                return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$purchasePlan->uuid, $plan->id])->with('error', $e->getMessage());
            }

            $this->saveSubscription($subscription, $purchasePlan->id);

            if (isset($response['message']) && !empty($response['message'])) {
                return redirect('thankyou')->with('success', 'Subscriptions successfull and ' . $response['message']);
            } else {
                return redirect('thankyou')->with('success', 'Subscriptions successfull');
            }
        } else {
            return Redirect()->action('RecurlyController@recurlyProductUpgrade', [$purchasePlan->uuid, $plan->id])->withErrors([
                'error' => 'Invalid Plan, Subscription, Account, or BillingInfo data.',
            ]);
        }
    }

    /**
     * Save the Subscription in Transaction table
     * @param $subscription
     * @param $customer_purchase_plan_id
     */
    protected function saveSubscription($subscription, $customer_purchase_plan_id){
        $transaction = new Transaction();
        $transaction->customer_purchase_plan_id = $customer_purchase_plan_id;
        $transaction->uuid = $subscription->uuid;
        $transaction->state = $subscription->state;
        $transaction->unit_amount_in_cents = $subscription->unit_amount_in_cents;
        $transaction->quantity = $subscription->quantity;
        $transaction->activated_at = $subscription->activated_at;
        $transaction->save();
    }

    public function renewRecurlyForm(Request $request){
        $uuid = $request->get('uuid');
        $purchase_plan_id = $request->get('purchase_plan_id');
        $product_plan_id = $request->get('product_plan_id');

        $address1            = Input::get('address1');
        $city                = Input::get('city');
        $state               = Input::get('state');
        $country             = Input::get('country');
        $recurly_token_id    = Input::get('recurly-token');

        try {
            $billing_info = $this->recurlyService->updateBillingInfo($request->all(), Auth::user());
        } catch (\Recurly_NotFoundError $e) {
            return redirect()->to('/product-details/'.$uuid)->with('error', $e->getMessage());
        } catch (Recurly_ValidationError $e) {
            return redirect()->to('/product-details/'.$uuid)->with('error', $e->getMessage());
        }

        try {
            $subscription = Recurly_Subscription::get($uuid);
            $subscription->collection_method = 'automatic';
            $state = $subscription->state;
            $subscription->updateImmediately();
            if($state == "canceled"){
                $subscription->reactivate();
            }
        } catch (\Recurly_ValidationError $e) {
            if ($e->getMessage()){
                $error_message= $e->getMessage();
            } else {
                $error_message= $e;
            }
            return redirect()->to('/product-details/'.$uuid)->withErrors([
                'error' => 'Error: '.$error_message,
            ]);

        }

        return redirect()->to('/product-details/'.$uuid)->with('success','Renewed Successfully');
    }



    public function renewRecurlyFormAPI(Request $request){
        $uuid = $request->get('uuid');
        $purchase_plan_id = $request->get('purchase_plan_id');
        $product_plan_id = $request->get('product_plan_id');
        Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');

        try {
            $subscription = Recurly_Subscription::get($uuid);
            $state = $subscription->state;
            $subscription->updateImmediately();
            if($state == "canceled"){
                $subscription->reactivate();
            }

        } catch (\Recurly_ValidationError $e) {
            if ($e->getMessage()){
                $error_message= $e->getMessage();
            } else {
                $error_message= $e;
            }
            return Redirect('/products?error=error')->withErrors([
                'error' => 'Error: '.$error_message,
            ]);

        }
        return $this->renewRecurlyGeneral($purchase_plan_id, $uuid, $subscription, $product_plan_id);
    }

    protected function renewRecurlyGeneral($purchase_plan_id, $uuid, $subscription, $product_plan_id){
        /*
        $purchase_plan = PurchasePlans::find($purchase_plan_id);
        $purchase_plan->expiration_date = $subscription->current_period_ends_at;
        $purchase_plan->update();

        $plan = $purchase_plan->plan;

        if ($plan && $plan->plan_type == 'vpn_dedicated') {
            $this->vpnService->enableVPNServer($purchase_plan, Auth::user());
        } else {
            $this->proxyService->enableProxy($purchase_plan, Auth::user());
        }

        $this->saveSubscription($subscription, $purchase_plan_id);
        */
        return redirect()->to('/product-details/'.$uuid)->with('success','Renewed Successfully');
    }

    /**
     * Create/Edit recurly product validator
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function shippingDataValidator(array $data) {
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

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function accountDataValidator(array $data) {
        return Validator::make($data, [
          'first_name' => 'required|max:255',
          'last_name' => 'required|max:255',
          'email' => 'required|email|max:255|unique:users',
          'password' => 'required|confirmed|min:8|regex:/^.*(?=.{3,})(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\X]).*$/',
        ],
          ['password.regex' => "Password should meet these guidelines: <br />
        					  English uppercase characters (A – Z) <br />
							  English lowercase characters (a – z) <br />
							  Base 10 digits (0 – 9) <br />
							  Unicode characters"]);
    }

    public function saveShippingData($uuid, User $user, $data) {
        ShippingData::create([
            'uuid' => $uuid,
            'customer_id' => $user->id,
            'first_name' => $data['shipping_first_name'],
            'last_name' => $data['shipping_last_name'],
            'address' => $data['shipping_address1'],
            'city' => $data['shipping_city'],
            'country' => $data['shipping_country'],
            'postal_code' => $data['shipping_postal_code'],
            'state' => $data['shipping_state'],
            'phone' => $data['shipping_phone'],
            'activate' => (isset($data['activation_rule']) && $data['activation_rule']) ? 1 : 0,
        ]);
    }
}
