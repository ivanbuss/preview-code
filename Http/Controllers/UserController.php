<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResetEmailRequest;
use App\Http\Requests\UserProfileUpdateRequest;
use App\Models\CustomerProxyData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\Services\Settings;
use App\User;
use Carbon\Carbon;
use Faker\Provider\cs_CZ\DateTime;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Recurly_Account;
use Recurly_Client;
use Recurly_NotFoundError;
use Hash;

class UserController extends Controller
{

	protected $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	public function getResetEmailForm(){
		return view('auth.emails.reset_email');
	}

	public function postResetEmailForm(ResetEmailRequest $request){
		$email_reset_code = str_random(10).Auth::user()->id.str_random(12);
		$put_reset_code= Auth::user()->update(['email_reset_code'=>$email_reset_code]);
		if ($put_reset_code){

			$link = URL::route('update_email_address',[$email_reset_code,$request->get('email')]);
			$message = $this->settings->get('email_change_email');
			$body = text_replace($message, [
				'{{ $user->first_name }}' => Auth::user()->first_name,
				'{{ $link }}' => $link,
			]);

			if ($this->settings->get('')) {
				$recipients_array = [$request->get('email')];
				Mail::send('emails.common', ['body' => $body], function ($m) use ($recipients_array) {
					$m->from(config('mail.from.address'), config('mail.from.name'));
					$m->to($recipients_array)->subject('Error Report');
				});
			} else {
				\Zendesk::tickets()->create([
					'type' => 'task',
					'tags'  => array('email_change'),
					'subject'  => 'Change Your Account Email',
					'comment'  => array(
						'body' => $body
					),
					'requester' => array(
						'locale_id' => '1',
						'name' => Auth::user()->name,
						'email' => $request->get('email'),
					),
					'priority' => 'normal',
				]);
			}

			return redirect('/user-profile')
				->with('success','We have sent you an email on new email address. Verify your new email address')->with('action', 'reset_email');
		}
		else{
			return Redirect::back()->withErrors([
				'error' => 'Failed to update email address. Please try again.',
				])->with('action', 'reset_email');;
		}


		return view('auth.emails.reset_email');
	}

	public function updateEmailAddress($email_reset_code,$new_email){
		$user       =  User::where('email_reset_code','=',$email_reset_code)->first();

		if($user) {
			$user->email =$new_email;
			$user->email_reset_code='';
			if($user->save())
			{
				/********************* update recurly account*************/
				Recurly_Client::$subdomain  =     env('RECURLY_SUBDOMAIN');
				Recurly_Client::$apiKey     =     env('RECURLY_APIKEY');
				try {
					$account = Recurly_Account::get($user->user_identifier);
					$account->email = $user->email;
					$account->update();

				} catch (Recurly_NotFoundError $e) {
					if($e->getMessage()){
						$error_message= $e->getMessage();
					}else{
						$error_message= $e;
					}
					DB::table('log_failed_registration')->insert([
						'user_id'        =>  $user->id,
						'action_on' => 'recurly-user-update',
						'error_message'  => $error_message,
					]);
				}

				return redirect('/user-profile')
				->with('success','Your email address has been updated');
			}
		}
		return redirect('/')
		->with('error','ooops We could not update account. Try again later.');
	}


	public function userProfile(){
        $user= Auth::user();
		$terms = $this->settings->get('terms');
		return view('auth.user_profile', ['user'=>$user, 'terms'=>$terms]);
	}
	public function updateUserProfile(UserProfileUpdateRequest $request,$user_id) {
		if ($request->user()->id != $user_id) abort(403);
		$user = User::where('id',$user_id)->first();
		if ($user){
			$user->first_name   =   Input::get('first_name');
			$user->last_name    =   Input::get('last_name');
			$user->save();
			return Redirect::back()->with('success', 'Account successfully updated')->with('action', 'profile_edit');
		}
		else{
			return Redirect::back()->with('error', 'Something went wrong, user not found!')->with('action', 'profile_edit');
		}
	}

	public function showThankYou(){
		return view('recurly.thankyou');
	}

	public function changePasswordPage() {
		return view('auth.passwords.change-password');
	}

	public function changePasswordFunc(Request $request) {
		$this->validate($request, [
        	'new-password' => 'required|min:6|regex:/^.*(?=.{3,})(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\X])(?=.*[!$@#%]).*$/'
    	]);

		$new_password = $request->input('new-password');
		$r_new_password = $request->input('r-new-password');

		$user = Auth::user();
		$oldhash = Hash::make($request->get('old-password'));
		if ($oldhash == Auth::user()->password) {
			if($new_password==$r_new_password) {
				$user->password = Hash::make($new_password);
				$user->save();
				return redirect()->back()->with('success', 'Password Updated Successfully')->with('action', 'change_pass');
			} else {
				return redirect()->back()->withErrors([
				'error' => 'New Password not matched with Repeat Password',
				])->with('action', 'change_pass');
			}
		} else {
			return redirect()->back()->withErrors([
				'old-password' => 'Old Password not matched',
				])->with('action', 'change_pass');
		}
	}

	public function displayUsers() {
		return view('recurly.admin.users.list');
	}

	public function displayUsersTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'username'],
			2 => ['name'=>'ip_address'],
            3 => ['name'=>'user_identifier'],
            4 => ['name'=>'first_name'],
            5 => ['name'=>'last_name'],
            6 => ['name'=>'email'],
            7 => ['name'=>'referrer'],
            8 => ['name'=>'created_at'],
        ];

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = User::select('users.*');

        $recordsTotal = $query->count();

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('username', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('email', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('first_name', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('last_name', 'LIKE', '%'.$search['value'].'%');
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

        $items = [];
        foreach($collective as $item) {
            $items[] = [
                $item->id,
                $item->username,
				$item->ip_address,
                $item->user_identifier,
                $item->first_name,
                $item->last_name,
                $item->email,
                $item->referrer,
                $item->created_at->format('F jS Y g:i A'),
                '<a class="btn btn-default btn-sm" href="'.action('UserController@userEdit', $item->id).'"><span class="glyphicon glyphicon-edit"></span></a>'
            ];
        }

        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
	}

	public function userEdit($user_id) {

		$user = User::find($user_id); $proxy_remainingTime = '';
        $purchased = $user->hasPurchased(); $vpn_remainingTime = '';

        $proxy_trial_model = $user->getTrial(TRUE); $proxy_active = FALSE;
        if ($proxy_trial_model) $proxy_active = $proxy_trial_model->isActive();
        if ($proxy_active) $proxy_remainingTime = $proxy_trial_model->remainingTime();

		$vpn_trial_model = $user->getVPNTrial(TRUE); $vpn_active = FALSE;
		if ($vpn_trial_model) $vpn_active = $vpn_trial_model->isActive();
		if ($vpn_active) $vpn_remainingTime = $vpn_trial_model->remainingTime();

		return view('recurly.admin.edit-user', [
            'user'=>$user,
            'proxy_trial_model'=>$proxy_trial_model,
            'proxy_active'=>$proxy_active,
            'proxy_remainingTime'=>$proxy_remainingTime,
			'vpn_trial_model'=>$vpn_trial_model,
			'vpn_active'=>$vpn_active,
			'vpn_remainingTime'=>$vpn_remainingTime,
            'purchased'=>$purchased,
        ]);
	}

	public function changeUserStatus(Request $request) {
		$user_id = $request->input('user-id');
		$user = User::find($user_id);
		$status = $request->input('status');
		if($status==1) {
			$user->active = 1;
			$user->save();
			return redirect()->back()->with('success', 'User Activated');
		}
		elseif ($status==0) {
			$user->active = 0;
			$user->save();
			return redirect()->back()->with('success', 'User Deactivated');
		}
	}


	public function changeUserPassword(Request $request) {

		$this->validate($request, $this->getResetValidationRules(), ['password.regex' => "Password should meet these guidelines: <br />
        					  English uppercase characters (A – Z) <br />
							  English lowercase characters (a – z) <br />
							  Base 10 digits (0 – 9) <br />
							  Non-alphanumeric (For example: !, $, @, #, or %) <br />
							  Unicode characters"]);

		$user_id = $request->input('user-id');
		$user = User::find($user_id);
		$new_password = $request->input('password');
		$r_new_password = $request->input('password_confirmation');

		if ($new_password == $r_new_password) {
			$user->password = Hash::make($new_password);
			$user->save();
			return redirect()->back()->with('success', 'Password Updated Successfully');
		}

		else {
			return redirect()->back()->withErrors([
				'error' => 'Passwords are not matched',
				]);
		}

	}

    public function postTrialReactivate(Request $request, User $user) {
        $this->validate($request, [
            'duration' => 'integer',
        ]);

        //$purchased = $user->hasPurchased();
        //if (!$purchased) {
		$trial = $user->getTrial();
		$active = FALSE;
		if ($trial) {
			$active = $trial->isActive();
		}
		if ($trial && $active) {
			if ($trial->extend($request->get('duration'))) return redirect()->back()->with('success', 'Free Trial has been extended successfully.');
		} else if ($trial) {
			if ($trial->reactivate()) return redirect()->back()->with('success', 'Free Trial has been reactivated successfully.');
		}
        //}
        return redirect()->back()->with('error', 'Error has occurred.');
    }

    public function postVpnTrialReactivate(Request $request, User $user) {
        $this->validate($request, [
            'duration' => 'integer',
        ]);

        //$purchased = $user->hasPurchased();
        //if (!$purchased) {
		$trial = $user->getVPNTrial();
		$active = FALSE;
		if ($trial) {
			$active = $trial->isActive();
		}
		if ($trial && $active) {
			if ($trial->extend($request->get('duration'))) return redirect()->back()->with('success', 'Free Trial has been extended successfully.');
		} else if ($trial) {
			if ($trial->reactivate()) return redirect()->back()->with('success', 'Free Trial has been reactivated successfully.');
		}
        //}
        return redirect()->back()->with('error', 'Error has occurred.');
    }


	/**
	 * Get the password reset validation rules.
	 *
	 * @return array
	 */
	protected function getResetValidationRules()
	{
		return [
			'password' => 'required|confirmed|min:6|regex:/^.*(?=.{3,})(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\X])(?=.*[!$@#%]).*$/',
		];
	}


}
