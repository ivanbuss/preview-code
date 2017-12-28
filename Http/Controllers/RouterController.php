<?php

namespace App\Http\Controllers;


use App\Models\CustomerRouterData;
use App\Models\CustomerVPNData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\Models\Transaction;
use App\Services\RecurlyService;
use App\Services\Settings;
use App\Services\StoreRouterService;
use App\Services\StoreVPNService;
use Illuminate\Http\Request;

use App\Http\Requests;
use Carbon\Carbon;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;


class RouterController extends Controller
{

    protected $settings;
    protected $vpnService;
    protected $routerService;
    protected $recurlyService;

    function __construct(StoreVPNService $vpnService, StoreRouterService $routerService, RecurlyService $recurlyService, Settings $settings) {
        $this->vpnService = $vpnService;
        $this->routerService = $routerService;
        $this->settings = $settings;
        $this->recurlyService = $recurlyService;
    }

    public function postFlashRouter($uuid, $plan_id, Request $request) {
        $purchaseplan = PurchasePlans::where('customer_id', Auth::user()->id)->where('uuid', $uuid)->firstOrFail();

        $routerData = CustomerRouterData::where('uuid', $uuid)
            ->where('customer_id', Auth::user()->id)
            ->where('plan_id', $plan_id)
            ->firstOrFail();

        $validator = $this->routerFlashValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $location_id = null; $vpn_server_id = null;
        $server_id = $request->get('location_id');
        if (is_numeric($server_id)) $location_id = $server_id;
            else $vpn_server_id = $server_id;

        $vpn_server = CustomerVPNData::where('server_id', $vpn_server_id)
          ->where('customer_id', Auth::user()->id)
          ->where('enabled', 1)->where('expired', 0)
          ->first();

        if ($vpn_server) $routerData->vpn_server_id = $vpn_server->id;
            else $routerData->vpn_server_id = null;
        if ($location_id) $routerData->location_id = $location_id;
            else $routerData->location_id = null;

        $routerData->wifi_ssid = $request->get('wifi_name');
        $routerData->lan_ip = $request->get('lan_ip');
        $routerData->lan_netmask = $request->get('net_mask');
        $routerData->dns_server1 = $request->get('dns1');
        $routerData->dns_server2 = $request->get('dns2');

        $response = $this->routerService->changeRouter($routerData, Auth::user(), $request->get('wifi_pass'));
        if (!$response['success'] || $response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['error']);
        }
        $routerData->save();

        return redirect()->back()->with('success', 'Router has been updated.');
    }

    function getRegistration(Request $request) {
        return view('recurly.router.product_router_register');
    }

    function postRegisterRouter(Request $request) {
        $validator = $this->registerValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
              $request, $validator
            );
        }

        $code = $request->get('code');
        $code_array = explode('-', $code);
        $macaddress = isset($code_array[0]) ? $code_array[0] : null;
        $registration_code = isset($code_array[1]) ? $code_array[1] : null;

        $verify = $this->routerService->verifyRouter($macaddress, $registration_code, $request->user());
        if (!$verify) return redirect()->action('RouterController@showErrorPage');

        $customer_router_data = CustomerRouterData::where('customer_id', $request->user()->id)
          ->where('macaddress', $macaddress)->first();

        if ($customer_router_data) {
            $response = $this->routerService->registerRouter($customer_router_data, $macaddress, $registration_code, $request->user());
            if (!$response['success'] || $response['success'] == FALSE) {
                return redirect()->action('RouterController@showErrorPage');
                //return redirect()->back()->with('error', $response['error']);
            }
            $customer_router_data->registered = 1;
            $customer_router_data->save();
            return redirect()->action('RouterController@showThankYou');
            //return redirect()->action('HomeController@getDashboard')->with('success', 'Router registered successfully');
        } else {
            $routers_count = CustomerRouterData::where('macaddress', $macaddress)->count();
            if ($routers_count == 0) {
                /*
                 * 1. Create recurly subscription
                 * 2. Create router service
                 * 3. Save router service
                 */

                try {
                    $account = $this->recurlyService->getAccount($request->user());
                } catch (\Recurly_NotFoundError $e) {
                    return redirect()->back()->with('error', $e->getMessage());
                }

                $plan_code = $this->settings->get('offline_router_product');
                $plan = RecurlyProducts::where('plan_code', $plan_code)->first();

                $subscriptionResponce = $this->recurlyService->createManualSubscription($plan_code, $account);
                if (!$subscriptionResponce['success']) {
                    return redirect()->back()->withErrors([
                      'error' => 'Error: '.$subscriptionResponce['error'],
                    ]);
                }
                $subscription = $subscriptionResponce['subscription'];
                $invoice = $subscription->invoice->get();
                if ($invoice->state == 'open') {
                    $this->recurlyService->markInvoiceAsPaid($invoice->invoice_number);
                }

                $response = $this->routerService->createRouterService($plan, $request->user(), $subscription->uuid, $macaddress, NULL, $request->getClientIps(), $subscription->current_period_ends_at);
                $purchasePlans = $response['purchase_plan'];
                if (!$response['success']) {
                    $this->recurlyService->terminateSubscription($subscription->uuid, $purchasePlans, $request->user());
                    return redirect()->back()->withErrors([
                      'error' => 'Error: '.$response['error'],
                    ]);
                }

                $customer_router_data = $response['routerdata'];

                $this->saveSubscription($subscription, $purchasePlans->id);

                $response = $this->routerService->registerRouter($customer_router_data, $macaddress, $registration_code, $request->user());
                if (!$response['success'] || $response['success'] == FALSE) {
                    return redirect()->action('RouterController@showErrorPage');
                }
                $customer_router_data->registered = 1;
                $customer_router_data->save();

                return redirect()->action('RouterController@showThankYou');
            } else return redirect()->back()->with('error', 'Router with this mac address already exists');
        }
    }

    public function showThankYou(){
        return view('recurly.router.product_router_register_success');
    }

    public function showErrorPage() {
        return view('recurly.router.product_router_register_error');
    }

    public function postResetRouter($uuid, $plan_id, Request $request) {
        $purchaseplan = PurchasePlans::where('customer_id', Auth::user()->id)->where('uuid', $uuid)->firstOrFail();

        $routerData = CustomerRouterData::where('uuid', $uuid)
          ->where('customer_id', Auth::user()->id)
          ->where('plan_id', $plan_id)
          ->firstOrFail();

        $password = bin2hex(openssl_random_pseudo_bytes(4));
        $routerData->wifi_ssid = 'VPNSTARS';
        $routerData->lan_ip = '10.3.2.1';
        $routerData->port = 1194;
        $routerData->lan_netmask = '255.255.255.0';
        $routerData->dns_server1 = '208.67.222.222';
        $routerData->dns_server2 = '208.67.220.220';

        $response = $this->routerService->changeRouter($routerData, Auth::user(), $password);
        if (!$response['success'] || $response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['error']);
        }
        $routerData->save();

        $message = $this->settings->get('email_router_reset');
        $email_body = text_replace($message, [
          '{{ $ssid }}' => $routerData->wifi_ssid,
          '{{ $wifi_password }}' => $routerData->wifi_password,
        ]);
        if ($this->settings->get('mail_use_default') == 1) {
            $recipients_array = [$request->user()->email];
            Mail::send('emails.common', ['body' => $email_body], function ($m) use ($recipients_array) {
                $m->from(config('mail.from.address'), config('mail.from.name'));
                $m->to($recipients_array)->subject('Error Report');
            });
        } else {
            \Zendesk::tickets()->create([
              'type' => 'task',
              'tags'  => array('reset_password'),
              'subject'  => 'Reset Password',
              'comment'  => array(
                'body' => $email_body
              ),
              'requester' => array(
                'locale_id' => '1',
                'name' => $request->user()->name,
                'email' => $request->user()->email,
              ),
              'priority' => 'normal',
            ]);
        }

        return redirect()->back()->with('success', 'Router settings has been reset.');
    }

    /**
     * Router update server validator
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function routerFlashValidator(array $data) {
        $rules = [
            'vpn_server' => 'exists:customers_vpn_data,id,customer_id,' . Auth::user()->id,
            'wifi_name' => 'required|max:255',
            'wifi_pass' => 'max:255',
            'lan_ip' => 'required|max:255',
            'net_mask' => 'required|max:255',
            'dns1' => 'required|max:255',
            'dns2' => 'max:255',
        ];
        return Validator::make($data, $rules);
    }

    protected function registerValidator(array $data = []) {
        $rules = [
          'code' => 'required|max:255',
        ];
        return Validator::make($data, $rules);
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

}
