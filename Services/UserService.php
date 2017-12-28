<?php

namespace App\Services;


use App\Models\CustomerVPNData;
use App\Models\CustomerVPNUsers;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class UserService {

    public function create(array $data, $activation = TRUE) {
        $trial_plan_code = '';
        if (isset($data['trial_plan_code'])) {
            $trial_plan_code = $data['trial_plan_code'];
        }
        $free_vpn_plan_code = '';
        if (isset($data['free_vpn_plan_code'])) {
            $free_vpn_plan_code = $data['free_vpn_plan_code'];
        }
        $trial_vpn_plan_code = '';
        if (isset($data['trial_vpn_plan_code'])) {
            $trial_vpn_plan_code = $data['trial_vpn_plan_code'];
        }
        $selected_plan_code = '';
        if (isset($data['plan_code'])) {
            $selected_plan_code = $data['plan_code'];
        }

        $code = str_random(60);
        user_identifier:
        $user_identifier = randomString(12, 'mix');
        $user_identifier_exist = User::where('user_identifier', $user_identifier)->first();
        if ($user_identifier_exist) {
            goto user_identifier;
        }

        $user = User::create([
          'first_name' => $data['first_name'],
          'last_name' => $data['last_name'],
          'email' => $data['email'],
          'activation_code' => $code,
          'trial_plan_code' => $trial_plan_code,
          'free_vpn_plan_code' => $free_vpn_plan_code,
          'trial_vpn_plan_code' => $trial_vpn_plan_code,
          'referrer' => isset($data['referrer']) ? $data['referrer'] : null,
          'user_identifier' => $user_identifier, // use for recurly account code
          'ip_address' => $data['ip_address'],
          'active' => 0,
          'password' => bcrypt($data['password']),
        ]);
        if ($user) {
            $user->username = 'customer_'.$user->id;
            $user->save();

            if ($activation) $this->sendActivationEmail($user, $code, $selected_plan_code);

            return $user;
        }
        return $user;
    }

    public function sendActivationEmail(User $user, $activation_code, $selected_plan_code = null) {
        $settings = new Settings();
        if ($selected_plan_code) {
            $link = URL::route('account-activate', ['code'=>$activation_code, 'plan_code'=>$selected_plan_code]);
        } else {
            $link = URL::route('account-activate', ['code'=>$activation_code]);
        }
        $message = $settings->get('email_activate_account');
        $body = text_replace($message, [
          '{{ $user->first_name }}' => $user->first_name,
          '{{ $link }}' => $link,
        ]);

        if ($settings->get('mail_use_default') == 1) {
            $recipients_array = [$user->email];
            Mail::send('emails.common', ['body' => $body], function ($m) use ($recipients_array) {
                $m->from(config('mail.from.address'), config('mail.from.name'));
                $m->to($recipients_array)->subject('Error Report');
            });
        } else {
            \Zendesk::tickets()->create([
              'type' => 'task',
              'tags'  => array('activation'),
              'subject'  => 'Activate Your Account',
              'comment'  => array(
                'body' => $body
              ),
              'requester' => array(
                'locale_id' => '1',
                'name' => $user->first_name .' '.$user->last_name,
                'email' => $user->email,
              ),
              'priority' => 'normal',
            ]);
        }
    }

    public function getTrialPlans() {
        $settings = new Settings();

        $data = []; $proxy_product = null; $free_vpn_product = null; $vpn_product = null;
        $proxy_trial_product_code = $settings->get('proxy_trial_product');
        $free_vpn_product_code = $settings->get('vpn_free_product');
        $vpn_trial_product_code = $settings->get('vpn_trial_product');

        if ($proxy_trial_product_code) {
            $proxy_product = RecurlyProducts::where('plan_type', '!=', 'vpn_dedicated')->where('billing_type', 'trial')->where('plan_code',  $proxy_trial_product_code)->first();
            if ($proxy_product) $data['trial_plan_code'] = $proxy_product->plan_code;
            else $data['trial_plan_code'] = '';
        }

        if ($free_vpn_product_code) {
            $free_vpn_product = RecurlyProducts::where('plan_type', '=', 'vpn_dedicated')->where('billing_type', 'trial')->where('plan_code',  $free_vpn_product_code)->first();
            if ($free_vpn_product) $data['free_vpn_plan_code'] = $free_vpn_product->plan_code;
            else $data['free_vpn_plan_code'] = '';
        }

        if ($vpn_trial_product_code) {
            $vpn_product = RecurlyProducts::where('plan_type', '=', 'vpn_dedicated')->where('billing_type', 'trial')->where('plan_code',  $vpn_trial_product_code)->first();
            if ($vpn_product) {
                $data['trial_vpn_plan_code'] = $vpn_product->plan_code;
            } else {
                $data['trial_vpn_plan_code'] = '';
            }
        }
        return $data;
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function registrationValidator(array $data) {
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

    public function createRecurlyAccount(User $user) {
        $recurlyService = new RecurlyService();
        $recurlyAccountData = $recurlyService->createAccount($user);
        if (!$recurlyAccountData['success']) {
            DB::table('log_failed_registration')->insert([
              'user_id' => $user->id,
              'action_on' => 'recurly-user-create',
              'error_message' => $recurlyAccountData['error'],
            ]);
        }
    }

    public function activateStoreProxyAccount(User $user) {
        $activationData = array(
          "username" => $user->username,
          "password" => '123456789',
          'store_account_id' => $user->user_identifier
        );
        //encoding to json format
        $jsonActivationData = json_encode($activationData);
        $url = env('STOREPROXY_ACCOUNT_ACTIVATION');
        $activationResponse = curlWrap("storeproxy", $url, $jsonActivationData, "POST");
    }

    public function createProxyTrial(User $user, $ip) {
        $proxyService = new StoreProxyService();
        $trial_plan_code = $user->trial_plan_code;
        if ($trial_plan_code) {
            $trial_plan = RecurlyProducts::where('plan_code', $trial_plan_code)
              ->where('plan_type', '!=', 'vpn_dedicated')
              ->where('billing_type', 'trial')
              ->first();
            if ($trial_plan) {
                $uuid = PurchasePlans::getUniqueUUID();
                $response = $proxyService->createProxyService($trial_plan, $user, $uuid, $ip);
                return $response;
            }
        }
        return FALSE;
    }

    public function createFreeVPN(User $user, $ip) {
        $vpnService = new StoreVPNService();
        $free_vpn_plan_code  = $user->free_vpn_plan_code;
        if ($free_vpn_plan_code) {
            $free_vpn_plan = RecurlyProducts::where('plan_code', $free_vpn_plan_code)
              ->where('plan_type', '=', 'vpn_dedicated')
              ->where('billing_type', 'trial')
              ->first();
            if ($free_vpn_plan && $free_vpn_plan->isTrialSimpleVPN()) {
                $uuid = PurchasePlans::getUniqueUUID();
                $response = $vpnService->createVPNServer($free_vpn_plan, $user, $uuid, $ip);
                if ($response['success'] == FALSE) {
                    return $response;
                }
                $vpnData = CustomerVPNData::where('uuid', $uuid)
                  ->where('customer_id', $user->id)
                  ->where('plan_id', $free_vpn_plan->id)
                  ->first();
                $vpn_user = CustomerVPNUsers::where('customer_id', $user->id)->where('is_main', 1)->where('enabled', 1)->first();
                if ($vpnData && $vpn_user) {
                    $vpnService->createUserServerConnection($vpn_user, $vpnData);
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public function createTrialVPN(User $user, $ip) {
        $vpnService = new StoreVPNService();
        $trial_vpn_plan_code = $user->trial_vpn_plan_code;
        if ($trial_vpn_plan_code) {
            $trial_vpn_plan = RecurlyProducts::where('plan_code', $trial_vpn_plan_code)
              ->where('plan_type', '=', 'vpn_dedicated')
              ->where('billing_type', 'trial')
              ->first();
            if ($trial_vpn_plan && !$trial_vpn_plan->isTrialSimpleVPN()) {

                $uuid = PurchasePlans::getUniqueUUID();
                $response = $vpnService->createVPNServer($trial_vpn_plan, $user, $uuid, $ip);
                if ($response['success'] == FALSE) {
                    return $response;
                }
                $vpnData = CustomerVPNData::where('uuid', $uuid)
                  ->where('customer_id', $user->id)
                  ->where('plan_id', $trial_vpn_plan->id)
                  ->first();
                $vpn_user = CustomerVPNUsers::where('customer_id', $user->id)->where('is_main', 1)->where('enabled', 1)->first();
                if ($vpnData && $vpn_user) {
                    $response = $vpnService->assignUser($vpn_user, $vpnData, $user);
                    if ($response['success'] == FALSE) {
                        return $response;
                    }
                    return TRUE;
                }
            }
        }
        return FALSE;
    }
}
