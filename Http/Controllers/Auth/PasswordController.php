<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redirect;


class PasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;
	protected $redirectTo = '/login';

    protected $settings;


    /**
     * Create a new password controller instance.
     */
    public function __construct(Settings $settings)
    {
        $this->middleware('guest');
        $this->settings = $settings;
    }

    public function sendResetLinkEmail(Request $request)
    {
        $this->validate($request, ['email' => 'required|email']);

        $user = User::where('email',$request->get('email'))->first();
        if(count($user) == 0){
            $response = 'passwords.user';
        }else{
            $token = $this->createToken($user);
            $response = $this->emailResetLink($user, $token);
        }
        switch ($response) {
            case Password::RESET_LINK_SENT:
                return $this->getSendResetLinkEmailSuccessResponse($response);

            case Password::INVALID_USER:
            default:
                return $this->getSendResetLinkEmailFailureResponse($response);
        }
    }

    protected function emailResetLink($user, $token)
    {
        if(!empty($user->activation_code)) {
            
           return 'Activate Account First';
            exit();
        }
        $link = url('password/reset', $token);
        $message = $this->settings->get('email_reset_password');
        $code = urlencode($user->getEmailForPasswordReset());
        $body = text_replace($message, [
            '{{ $user->first_name }}' => $user->first_name,
            '{{ $link }}' => $link,
            '{{ $code }}' => $code,
        ]);

        if ($this->settings->get('mail_use_default') == 1) {
            $recipients_array = [$user->email];
            Mail::send('emails.common', ['body' => $body], function ($m) use ($recipients_array) {
                $m->from(config('mail.from.address'), config('mail.from.name'));
                $m->to($recipients_array)->subject('Error Report');
            });
        } else {
            \Zendesk::tickets()->create([
              'type' => 'task',
              'tags'  => array('reset_password'),
              'subject'  => 'Reset Password',
              'comment'  => array(
                'body' => $body
              ),
              'requester' => array(
                'locale_id' => '1',
                'name' => $user->name,
                'email' => $user->email,
              ),
              'priority' => 'normal',
            ]);
        }

        return 'passwords.sent';
    }

    protected function createToken($user)
    {
        $token = hash_hmac('sha256', Str::random(40), 'secret');
        DB::table('password_resets')->where('email', $user->email)->delete();
        DB::table('password_resets')->insert(
            ['email' => $user->email, 'token' => $token, 'created_at' => Carbon::now()]
        );
        return $token;
    }


    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postReset(Request $request)
    {
        return $this->reset($request);
    }

    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function reset(Request $request)
    {
        $this->validate($request, $this->getResetValidationRules(), ['password.regex' => "Password should meet these guidelines: <br />
        					  English uppercase characters (A – Z) <br />
							  English lowercase characters (a – z) <br />
							  Base 10 digits (0 – 9) <br />
							  Non-alphanumeric (For example: !, $, @, #, or %) <br />
							  Unicode characters"]);

        $credentials = $request->only(
            'email', 'password', 'password_confirmation', 'token'
        );

        $broker = $this->getBroker();

        $response = Password::broker($broker)->reset($credentials, function ($user, $password) {
            $this->resetPassword($user, $password);
        });

        switch ($response) {
            case Password::PASSWORD_RESET:
                return $this->getResetSuccessResponse($response);

            default:
                return $this->getResetFailureResponse($request, $response);
        }
    }

    /**
     * Get the password reset validation rules.
     *
     * @return array
     */
    protected function getResetValidationRules()
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8|regex:/^.*(?=.{3,})(?=.*[a-zA-Z])(?=.*[0-9])(?=.*[\d\X])(?=.*[!$@#%]).*$/',
        ];
    }
}
