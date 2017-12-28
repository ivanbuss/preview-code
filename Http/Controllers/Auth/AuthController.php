<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\LoginCustomRequest;
use App\Models\CustomerProxyData;
use App\Models\CustomerVPNData;
use App\Models\CustomerVPNUsers;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\Services\Settings;
use App\Services\StoreProxyService;
use App\Services\StoreVPNService;
use App\Services\UserService;
use App\User;
use Carbon\Carbon;
use App\Services\RecurlyService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;

use Recurly_Account;
use Recurly_Client;
use Symfony\Component\HttpFoundation\Request;
use Validator;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Foundation\Auth\AuthenticatesAndRegistersUsers;
use Auth;

class AuthController extends Controller {
    /*
    |--------------------------------------------------------------------------
    | Registration & Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users, as well as the
    | authentication of existing users. By default, this controller uses
    | a simple trait to add these behaviors. Why don't you explore it?
    |
    */

    use AuthenticatesAndRegistersUsers, ThrottlesLogins;

    /**
     * Where to redirect users after login / registration.
     *
     * @var string
     */
    protected $redirectTo = '/dashboard';

    /**
     * Create a new authentication controller instance.
     *
     * @return void
     */

    protected $recurlyService;
    protected $proxyService;
    protected $vpnService;
    protected $userService;
    protected $settings;

    public function __construct(RecurlyService $recurlyService, StoreProxyService $proxyService, StoreVPNService $vpnService, UserService $userService, Settings $settings) {
        $this->middleware('guest', ['except' => 'logout']);
        $this->recurlyService = $recurlyService;
        $this->proxyService = $proxyService;
        $this->vpnService = $vpnService;
        $this->userService = $userService;
        $this->settings = $settings;
    }

    /**
     * Show the application login form.
     *
     * @return \Illuminate\Http\Response
     */
    public function showLoginForm(Request $request) {
        $plan_code = $request->get('plan_code');
        $view = property_exists($this, 'loginView')
            ? $this->loginView : 'auth.authenticate';

        if (view()->exists($view)) {
            return view($view, ['plan_code'=>$plan_code]);
        }

        return view('auth.login', ['plan_code'=>$plan_code]);
    }

    public function getRegister(Request $request) {
        $plan_code = $request->get('plan_code');
        if (property_exists($this, 'registerView')) {
            if ($request->has('ref')) return Response::view($this->registerView, ['plan_code'=>$plan_code])->withCookie('proxystars_ref', $request->get('ref'), 60);
                else return Response::view($this->registerView, ['plan_code'=>$plan_code]);
        }

        if ($request->has('ref')) return Response::view('auth.register', ['plan_code'=>$plan_code])->withCookie('proxystars_ref', $request->get('ref'), 60);
            else return Response::view('auth.register', ['plan_code'=>$plan_code]);
    }

    protected function referer(Request $request) {

    }

    public function register(Request $request) {
        $validator = $this->userService->registrationValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $data = $request->all();
        $data['referrer'] = $request->cookie('proxystars_ref');

        $trial_plans = $this->userService->getTrialPlans();
        $data['trial_plan_code'] = isset($trial_plans['trial_plan_code']) ? $trial_plans['trial_plan_code'] : null;
        $data['free_vpn_plan_code'] = isset($trial_plans['free_vpn_plan_code']) ? $trial_plans['free_vpn_plan_code'] : null;
        $data['trial_vpn_plan_code'] = isset($trial_plans['trial_vpn_plan_code']) ? $trial_plans['trial_vpn_plan_code'] : null;

        $data['ip_address'] = $request->getClientIp();

        $user = $this->userService->create($data);
        if ($user) {
            if ($user->active == 1) {
                Auth::guard($this->getGuard())->login($user);
                return redirect($this->redirectPath());
            }
            return redirect('/login')->with([
                'success' => 'New account has been created. Please verify your email address before login',
            ]);
        }

        return redirect('/register')->with([
            'error' => 'Failed to create your account please try again later.',
        ]);

    }

    public function getActivate($code, Request $request) {
        $user = User::where('activation_code', '=', $code)->where('active', '=', 0)->first();

        if ($user) {
            $user->active = 1;
            $user->activation_code = '';
            if ($user->save()) {


                /** --------------------RecurlySection Start------------ ------*/
                $this->userService->createRecurlyAccount($user);
                /** --------------------RecurlySection END------------ ------*/

                $this->userService->activateStoreProxyAccount($user);

                $response = $this->vpnService->createVPNService($user);
                if ($response['success'] == FALSE) {
                    return redirect('/')->with('error', $response['error']);
                }

                $random_pass = randomString(10, 'mix');
                $response = $this->vpnService->createVPNUser($user->email, $random_pass, $user, TRUE);
                if ($response['success'] == FALSE) {
                    return redirect('/')->with('error', $response['error']);
                }

                //-----      Request for Trial Plan Starts    ----------------//
                $proxy_trial_response = $this->userService->createProxyTrial($user, $request->getClientIps());
                $free_vpn_response = $this->userService->createFreeVPN($user, $request->getClientIps());
                $trial_vpn_response = $this->userService->createTrialVPN($user, $request->getClientIps());

                $plan_code = $request->get('plan_code'); $selected_plan = null;
                if ($plan_code) $selected_plan = RecurlyProducts::where('plan_code', $plan_code)->where('billing_type', '!=', 'trial')->first();

                if ($selected_plan) {
                    return redirect('/login?plan_code='.$selected_plan->plan_code)
                        ->with('success', 'Your account is activated! Please login to continue...');
                } else {
                    return redirect('/login')
                        ->with('success', 'Your account is activated! Please login to continue...');
                }

            }
        }
        return redirect('/')
            ->with('error', 'ooops We could not activate your acount. Try again later.');
    }

    public function login(LoginCustomRequest $request) {
        $email = Input::get('email');
        $password = Input::get('password');
        $remember_token = Input::get('remember');
        if ($remember_token == "on") {
            $remember = true;
        } else {
            $remember = false;
        }

        if (Auth::attempt(['email' => $email, 'password' => sha1($password)], $remember)) {
            $user = Auth::user();
            if (!$user->active) {
                Auth::logout();
                return Redirect::back()->withErrors([
                    'error' => 'Account has to be confirmed.',
                ]);
            }

            if ($user->role == 'admin') return redirect('/dashboard');

            $purchase_plans = PurchasePlans::select('customer_purchase_plans.*')->with('plan')
              ->leftJoin('customers_router_data', 'customers_router_data.uuid', '=', 'customer_purchase_plans.uuid')
              ->where('customer_purchase_plans.status', '!=', 'expired')
              ->where('customer_purchase_plans.customer_id', $request->user()->id)
              ->where(function ($query) {
                  $query->whereNull('customers_router_data.id')->orWhere(function ($subquery) {
                      $subquery->whereNotNull('customers_router_data.id')->where('customers_router_data.registered', 1);
                  });
              })
              ->get();
            $active_products = 0;
            foreach($purchase_plans as $purchase_plan) {
                $expiry_time = $purchase_plan->expiration_date;
                if (Carbon::now() < $expiry_time) {
                    $active_products++;
                    break;
                }
            }

            $plan_code = $request->get('plan_code'); $selected_plan = null;
            if ($plan_code) $selected_plan = RecurlyProducts::where('plan_code', $plan_code)->where('billing_type', '!=', 'trial')->first();
            if ($selected_plan) {
                if ($selected_plan->plan_type == 'vpn_dedicated') {
                    return redirect()->action('RecurlyController@recurlyProductsNew', [
                      'type' => 'vpn',
                      'plan_code' => $selected_plan->plan_code
                    ]);
                } else if ($selected_plan->plan_type == 'router') {
                    return redirect()->action('RecurlyController@recurlyProductsNew', [
                      'type' => 'router',
                      'plan_code' => $selected_plan->plan_code
                    ]);
                } else if ($selected_plan->plan_type == 'package') {
                    $category = $selected_plan->category; $type = 'router';
                    $parent_cat = $category->mainParent();
                    switch ($parent_cat->id) {
                        case 1:
                            $type = 'proxy';
                            break;
                        case 42:
                            $type = 'vpn';
                            break;
                        case 78:
                            $type = 'router';
                            break;
                    }
                    return redirect()->action('RecurlyController@recurlyProductsNew', ['type'=>$type, 'plan_code'=>$selected_plan->plan_code]);
                } else {
                    return redirect()->action('RecurlyController@recurlyProductsNew', ['type'=>'proxy', 'plan_code'=>$selected_plan->plan_code]);
                }
            }

            if ($active_products == 1) {
                if ($purchase_plan->plan->plan_type == 'package') {
                    foreach ($purchase_plan->plan->bundlePlans as $bundlePlan) {
                        $plan_id = $bundlePlan->id;
                        break;
                    }
                }
                else {
                    $plan_id = $purchase_plan->plan->id;
                }
                return redirect()->action('RecurlyController@productDetailForCustomers', ['uuid'=>$purchase_plan->uuid, $plan_id]);
            }


            return redirect('/dashboard');
        } else {
            return Redirect::back()->withErrors([
                'error' => 'These credentials do not match our records.',
            ]);
        }
    }


}
