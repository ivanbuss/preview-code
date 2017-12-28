<?php

/*
|--------------------------------------------------------------------------
| Routes File
|--------------------------------------------------------------------------
|
| Here is where you will register all of the routes in an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/
use Zendesk\API\HttpClient as ZendeskAPI;
use \Firebase\JWT\JWT;
use App\Models\RecurlyProducts;
use App\Models\PurchasePlans;
use Carbon\Carbon;

Route::group(['middleware' => 'web'], function () {
	/**      Store_Proxy - Create Proxy service Test         not used */

	Route::get('/storeproxy_create_test', ['as' => 'storeproxy_create_test', function () {

			$data = array(
				"store_proxy_id"    =>  'rttsrrt458998jhjsdfhj1748',
				"number_of_ports"   =>  33,
				"type"              =>  'shared',
				"region"            =>  3363,
				"region_changeable" =>  true,
				"rotation_period"   =>  6666,
				"store_account_id"  =>  Auth::user()->user_identifier,
			);
			//encoding to json format
			$jsondata= json_encode($data);
			$url  = env('STOREPROXY_CREATE');
			$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		dd($data);
			$message=$data->message;
			return Redirect::back()->with('success', 'Subscriptions successfull and '.$message);

	}]);


	Route::get('/storeproxy_create/{product_id}', ['as' => 'storeproxy_create', function ($product_id) {

		$product = \App\Models\RecurlyProducts::where('id',$product_id)->first();
		if($product){
			$data = array(
				"store_proxy_id"    =>  $product->plan_code,
				"number_of_ports"   =>  $product->anytime_ports,
				"type"              =>  $product->type,
				"region"            =>  $product->location,
				"region_changeable" =>  $product->region_changeable,
				"rotation_period"   =>  $product->rotation_period,
				"store_account_id"  =>  Auth::user()->user_identifier,
			);
			//encoding to json format
			$jsondata= json_encode($data);
			$url  = env('STOREPROXY_CREATE');
			$data = curlWrap("storeproxy",$url, $jsondata, "POST");
			$message=$data->message;
			return Redirect::back()->with('success', 'Subscriptions successfull and '.$message);
		}else{
			return Redirect::back()->withErrors([
				'error' => 'Invalid Plan, Subscription, Account, or BillingInfo data.',
			]);
		}

	}]);
	/**      Store_Proxy - Change Proxy service        */


		Route::post('/storeproxy_change/{product_id}', array(
			'as' => 'store_proxy_change_request',
			'uses'=>'RecurlyController@postChangeProxy'
		));






	Route::get('/storeproxy_change', function () {
		$data = array(
			"store_proxy_id"    =>  11,
			"region"            =>  5,
			"rotation_period"   =>  5,
			"ip_list"           => ['9.10.11.12'],
			"store_account_id"  =>  301,
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('STOREPROXY_CHANGE');
		$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		echo  $message=$data->message;
		dd($data);
		dd('test store proxy ');
	});


	/**      Store_Proxy - Delete Proxy service       */
	Route::get('/storeproxy_delete', function () {
		$data = array(
			"store_proxy_id"    =>  11,
			"store_account_id"  =>  301
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('STOREPROXY_DELETE');
		$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		echo  $message=$data->message;
		dd($data);
		dd('test store proxy ');
	});




	/**      Store_Proxy - Disable Proxy service       */
	Route::get('/storeproxy_disable', function () {
		$data = array(
			"store_proxy_id"    =>  11,
			"store_account_id"  =>  301
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('STOREPROXY_DISABLE');
		$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		echo  $message=$data->message;
		dd($data);
		dd('test store proxy ');
	});
	/**     Store_Proxy - Enable Proxy service      */
	Route::get('/storeproxy_enable', function () {
		$data = array(
			"store_proxy_id"    =>  11,
			"store_account_id"  =>  301
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('STOREPROXY_ENABLE');
		$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		echo  $message=$data->message;
		dd($data);
		dd('test store proxy ');
	});

	/**    Store - User account activation      */
	Route::get('/storeproxy_account_activation1', function () {
		$data = array(
			"username"          =>  'abdulrehman',
			"password"          =>  '123456',
			'store_account_id'  =>  '14001301593'
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('STOREPROXY_ACCOUNT_ACTIVATION');
		$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		echo  $message=$data->message;
		dd($data);
		dd('test store proxy ');
	});
	Route::get('/storeproxy_account_activation2', function () {
		$data = array(
			"username"          =>  'testuser',
			"password"          =>  '123456',
			'store_account_id'  =>  '14001301613'
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('STOREPROXY_ACCOUNT_ACTIVATION');
		$data = curlWrap("storeproxy",$url, $jsondata, "POST");
		echo  $message=$data->message;
		dd($data);
		dd('test store proxy ');
	});




	/**         Recurly Testing       */
Route::get('/admin_recurly', function () {
    Recurly_Client::$subdomain = 'testdots';
	Recurly_Client::$apiKey='be57747c23b741d386ea2f386364a815';
	try {

		$account = new Recurly_Account('smsbvcf46ydhg12');
		$account->email = 'abdulrahman@mindblazetech.com';
		$account->first_name = 'abdul';
		$account->last_name = 'rahman';
		$account->create();
      //echo '<a href="https://testdots.recurly.com/account/64d2646f7e8fb6cc6ccb73a004cf96e1">';
//		$account=  new Recurly_Account();
//		$account_info=$account->get('b6f5g78563');
//		echo "<pre>"; print_r($account_info); echo "</pre>";
//		echo $account_info->hosted_login_token;
//		dd($account_info->getHref());
//
		print "Account: $account\n";
		dd($account->errors);
        dd($account);

	} catch (Recurly_ValidationError $e) {
		dd($e->getMessage());
		print "Invalid Account: $e";
	}
	dd('testing recurly');
});

	/**            ZEND DESK TESTING            */

//first add "zendesk/zendesk_api_client_php": "dev-master",//" it will cause error with guzzel package 4.0."
Route::get('/test_zenddesk', function () {

	$zendesk_user_data['user'] = array(
		'name' => 'admin',
		'email' => 'admin@gmail.com',
		'details' => '',
		'role' => 'admin',
		'restriction' => '',
		'tags' => array('uuu','dasd'),
		'user_fields' => array(
			'user_id' => 32323,
			'company' => 'abc'
		)
	);

	$json = json_encode($zendesk_user_data);
	$data = curlWrap("zenddesk","/users.json", $json, "POST"); //
	dd($data);

	dd('testing zendesk');
});

	Route::get('/login_zendesk',function(){
		$key       = "ugP35L4o71PVJBxjqtKwMjT8wigjhUWwv4pqEQBbfj8aWqAd";
		$subdomain = "mindblazetech";
		$now       = time();
		$token = array(
			"jti"   => md5($now . rand()),
			"iat"   => $now,
			"name"  => 'admin',
			"email" => 'admin@gmail.com'
		);
		$jwt = JWT::encode($token, $key);
		$location = "https://" . $subdomain . ".zendesk.com/access/jwt?jwt=" . $jwt;
		if(isset($_GET["return_to"])) {
			$location .= "&return_to=" . urlencode($_GET["return_to"]);
		}

		return Redirect::to($location);
	});

	/**               Fresh Desk Testting                   */
	Route::get('/admin_freshdesk', function () {
		//creat_user in freshdesk
		$data = array(
			"user" => array("email"=>"abdulrahman@mindblazetech.com","name"=>"Abdul Rahman")
		);
		//encoding to json format
		$jsondata= json_encode($data);
		$url = env('FRESHDESK_CREATEUSER_URL');
		$data = curlWrap("freshdesk",$url, $jsondata, "POST"); //
		if(isset($data->user)){
			//dd($data->user);
			$user = \Illuminate\Support\Facades\Auth::user();
			$user->user_freshdesk_id =  $data->user->id;
			$user->save();
		}else{
			$message="";
			foreach($data as $error){
				$message.=$error[0].$error[1];
			}
			\Illuminate\Support\Facades\DB::table('log_failed_registration')->insert([
				'user_id'        =>  Auth::user()->id,
				'action_on' => 'freshdesk-user-create',
				'error_message'  => $message,
			]);
		}
		dd($data[0]);
		dd('testing fresh desk');
	});
	Route::get('/view_freshdesk', function () {
		//creat_user in freshdesk
		$jsondata="";
		$url = "https://mindblazetech.freshdesk.com/contacts/5544.json";
		$data = curlWrap("freshdesk",$url, $jsondata, "GET"); //
		dd($data);
		dd('testing fresh desk');
	});

	Route::get('/login_freshdesk', function () {
		define('FRESHDESK_SHARED_SECRET','207c347c6ecc9b4e726e68fe1981cf82');
		define('FRESHDESK_BASE_URL','https://mindblazetech.freshdesk.com');	//With Trailing slashes
		function getSSOUrl($strName, $strEmail) {
			$timestamp = time();
			$to_be_hashed = $strName . $strEmail . $timestamp;
			$hash = hash_hmac('md5', $to_be_hashed, FRESHDESK_SHARED_SECRET);
			$url= FRESHDESK_BASE_URL."/login/sso/?name=".urlencode($strName)."&email=".urlencode($strEmail)."&timestamp=".$timestamp."&hash=".$hash;
		    return $url;
		}
		header("Location: ".getSSOUrl("abdulrahman","abdulrahman@mindblazetech.com"));
		dd('testing fresh desk');
	});

	Route::get('/testmail', function () {
		Recurly_Client::$subdomain = 'testdots';
		Recurly_Client::$apiKey='be57747c23b741d386ea2f386364a815';
		$plans = Recurly_PlanList::get();
		foreach ($plans as $plan) {
			echo $plan->name.'<br>';
			echo $plan->description.'<br>';
			dd($plan);
		}
		dd('endddd');
		\Illuminate\Support\Facades\Mail::send('auth.emails.activate', array('link'=>URL::route('account-activate','sdaufasjfg'),'username'=>'abdulrahman'),
			function($message){
				$message->to('abdulrahman@mindblazetech.com','abdulrahman')->subject('Activate your account');
			});
	});
	//
});
/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| This route group applies the "web" middleware group to every route
| it contains. The "web" middleware group is defined in your HTTP
| kernel and includes session state, CSRF protection, and more.
|
*/

Route::group(['middleware' => ['web']], function () {

	Route::auth();
	Route::get('account-activate{code}', array(
		'as' => 'account-activate',
		'uses'=>'Auth\AuthController@getActivate'
	));

	Route::get('update-email-address/{email_reset_code}/{new_email}', array(
		'as' => 'update_email_address',
		'uses'=>'UserController@updateEmailAddress'
	));


});

Route::group(['middleware' => ['web','auth']], function () {
	Route::get('/', function () {
		if(Auth::check()){
			return redirect()->route('dashboard');
		}else{
			return view('auth.login');
		}

	});

	Route::get('/dashboard', ['as'=>'dashboard',function () {
		if(Auth::user()->role=='admin'){
			return view('admin_dashboard');
		}else{

			$expiry="";
			$product_id="";
			$show_box = false;
			$today = date('Y-m-d');
			if(Auth::user()->trial_plan_code!='') {
				$product = RecurlyProducts::where('plan_code',Auth::user()->trial_plan_code)->get();

				$user_id = Auth::user()->id;
				$expiry_time = '';
				$current_time = date('Y M d H:i:s');
				if(count($product) > 0) {
					$purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('plan_id', $product[0]->id)->orderBy('id', 'desc')->first();

					

					if ($purchase_plan) {
						$create_date = $purchase_plan->created_at;
						$time = $product[0]->duration;
						$dateinsec = strtotime($create_date);
						$newdate = $dateinsec + $time;
						$expiry_time = date('Y M d H:i:s', $newdate);

						if ($current_time < $expiry_time) {
							$show_box = true;
						}

					}
				}
			}	
			return view('user_dashboard')->with('expiry',$expiry_time)->with('pid',$product_id)->with('show_box',$show_box)->with('current_time',$current_time);
		}
	}]);


	Route::get('/get-reset-email-form', array(
		'as' => 'get-reset-email-form',
		'uses'=>'UserController@getResetEmailForm'
	));
	Route::post('post-reset-email-form', array(
		'as' => 'post-reset-email-form',
		'uses'=>'UserController@postResetEmailForm'
	));


	Route::get('/freshdesk-login', ['as'=>'freshdesk_login', function () {
		function getSSOUrl($strName, $strEmail) {
			$timestamp = time();
			$to_be_hashed = $strName .env('FRESHDESK_SHARED_SECRET'). $strEmail . $timestamp;
//			$to_be_hashed = $strName . $strEmail . $timestamp;
			$hash = hash_hmac('md5', $to_be_hashed, env('FRESHDESK_SHARED_SECRET'));
			$url= env('FRESHDESK_BASE_URL')."/login/sso/?name=".urlencode($strName)."&email=".urlencode($strEmail)."&timestamp=".$timestamp."&hash=".$hash;
			return $url;
		}
		header("Location: ".getSSOUrl(Auth::user()->first_name.' '.Auth::user()->last_name ,Auth::user()->email));
		dd('trying to login freshdesk');
	}]);

	//login recurly with out iframe
	Route::get('/recurly-login', ['as'=>'recurly_login', function () {
		Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
		Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');
		try {
			$account      = new Recurly_Account();
			$account_info = $account->get( Auth::user()->user_identifier);
			$login_token = $account_info->hosted_login_token;
			return \Illuminate\Support\Facades\Redirect::to(env('RECURLY_BASEURL').'/account/'.$login_token);
			exit;
			}
			catch (Recurly_ValidationError $e) {
			echo "<pre>"; print_r($e->getMessage()); echo "</pre>";die();
			}
		}]);

		Route::get('/recurly-iframe', ['as'=>'recurly_iframe', function () {
			Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
			Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');
			try {
				$account      = new Recurly_Account();
				$account_info = $account->get( Auth::user()->user_identifier);
				$login_token = $account_info->hosted_login_token;
				return view('recurly.recurly_iframe')->with('url',env('RECURLY_BASEURL').'/account/'.$login_token);
				exit;
			}
			catch (Recurly_ValidationError $e) {
				echo "<pre>"; print_r($e->getMessage()); echo "</pre>";die();
			}
		}]);

	Route::get('/products-old', array( //for customer
		'as'    =>  'recurly_products',
		'uses'  =>  'RecurlyController@recurlyProducts'
	));

	Route::get('/manage-products', array( //for admin
		'as'    =>  'manage_products',
		'uses'  =>  'RecurlyController@recurlyProducts'
	));
	Route::post('/manage-products', array( //for admin
		'as'    =>  'recurly_products_byfilter',
		'uses'  =>  'RecurlyController@recurlyProductsByFilter'
	));
	Route::post('/products-old', array( 
		'as'    =>  'recurly_products_byfilter_front',
		'uses'  =>  'RecurlyController@recurlyProductsByFilterFront'
	));

	Route::get('/manage-categories', array( //for admin
		'as'    =>  'manage_categories',
		'uses'  =>  'RecurlyController@getRecurlyCategories'
	));

	Route::get('/get_add_category_form', array(   //for admin - ajax request
		'as'    =>  'get_add_category_form',
		'uses'  =>  'RecurlyController@addRecurlyCategoryForm'
	));
	Route::post('/add-recurly-category', array(
		'as'    =>  'add_recurly_category',
		'uses'  =>  'RecurlyController@addRecurlyCategory'
	));
	Route::get('/get_update_category_form', array(   //for admin - ajax request
		'as'    =>  'get_update_category_form',
		'uses'  =>  'RecurlyController@updateRecurlyCategoryForm'
	));

	Route::post('/update-recurly-category/{category_id}', array(
		'as'    =>  'update_recurly_category',
		'uses'  =>  'RecurlyController@updateRecurlyCategory'
	));

	Route::get('/get_childs_category_by_parent_ajax', array(   //for admin - ajax request
		'as'    =>  'get_childs_category_by_parent_ajax',
		'uses'  =>  'RecurlyController@getChildsCategoriesByParent'
	));

	Route::get('/get_added_product_detail_ajax', array(   //for admin - ajax request
		'as'    =>  'get_added_product_detail_ajax',
		'uses'  =>  'RecurlyController@getAddedProductDetailAjax'
	));

	Route::delete('/delete-category/{category_id}/delete', [
		'as' => 'delete_category', 'uses' => 'RecurlyController@deleteCategory'
	]);

	Route::get('/create-proxy-product', [
		'as' => 'get_create_product_form',
		'uses' => 'RecurlyController@getRecurlyProductCreateForm'
	]);
	Route::post('/create-proxy-product', [
		'as' => 'post_create_product_form',
		'uses' => 'RecurlyController@postCreateRecurlyProduct'
	]);
	Route::get('/update-recurly-product/{plan_id}', [
		'as' => 'update_recurly_product_form',
		'uses' => 'RecurlyController@getRecurlyProductUpdateForm'
	]);
	Route::post('/update-recurly-product/{plan_id}/update', [
		'as' => 'post_update_recurly_product_form',
		'uses' => 'RecurlyController@postRecurlyProductUpdateForm'
	]);

	Route::delete('/delete-recurly-product/{plan_id}/delete', [
		'as' => 'delete_recurly_product_form',
		'uses' => 'RecurlyController@deleteRecurlyProductForm'
	]);

	Route::get('/get_duration_for_plan', array(   //for admin - ajax request
		'as'    =>  'get_duration_for_plan',
		'uses'  =>  'RecurlyController@getDurationForPlan'
	));

	Route::get('/test_product', ['as'=>'test_product', function () {
		return view('product');
	}]);

	Route::get('/product-details/{uuid}', array(
		'as' => 'product_detail_for_customer',
		'uses'=>'RecurlyController@productDetailForCustomers'
	));

	Route::get('/user-profile', array(
		'as' => 'user_profile',
		'uses'=>'UserController@userProfile'
	));
	Route::post('/update-user-profile/{user_id}/update', [
		'as' => 'update_user_profile', 'uses' => 'UserController@updateUserProfile'
	]);
	Route::post('/post-recurly-buy-form-api', [
		'as' => 'post_recurly_buy_form-api', 'uses' => 'RecurlyBuyformController@postRecurlyBuyFormAPI'
	]);
	Route::post('/post-recurly-buy-form', [
		'as' => 'post_recurly_buy_form', 'uses' => 'RecurlyBuyformController@postRecurlyBuyForm'
	]);
	Route::post('/renew_recurly_form', [
		'as' => 'renew_recurly_form', 'uses' => 'RecurlyBuyformController@renewRecurlyForm'
	]);
	Route::post('/renew_recurly_form_api', [
		'as' => 'renew_recurly_form_api', 'uses' => 'RecurlyBuyformController@renewRecurlyFormAPI'
	]);

	Route::post('/post_paypal_recurly_buy_form', [
		'as' => 'post_paypal_recurly_buy_form', 'uses' => 'UserController@postPayPalRecurlyBuyForm'
	]);
	Route::post('/request-authorized-ips/{plan_id}', [
		'as' => 'request_authorized_ips', 'uses' => 'RecurlyController@postAuthorizedIpsRequest'
	]);
	
	Route::post('/update-custom-plan/{plan_id}', [
		'as' => 'update_params_custom_plan', 'uses' => 'RecurlyController@updateParamsCustomPlan'
	]);

	Route::get('/thankyou', array(
		'as' => 'thankyou',
		'uses'=>'UserController@showThankYou'
	));

	Route::get('/products', array( 
		'as'    =>  'recurly_products_new',
		'uses'  =>  'RecurlyController@recurlyProductsNew'
	));

	Route::get('/get-level2-cats-ajax', array(  //for new product page - ajax request
		'as'    =>  'get_level_2_cats_ajax',
		'uses'  =>  'RecurlyController@getCategoryChildrenLevel2'
	));

	Route::get('/get-products-units', array(  //for products unit select - ajax request
		'uses'  =>  'RecurlyController@getUnitAndLocationSelect'
	));

	Route::get('/get-products-for-features', array(  //for new product page - ajax request
		'as'    =>  'get_products_for_features',
		'uses'  =>  'RecurlyController@getProductsFiltered'
	));

	Route::get('/change-password', array(  
		'as'    =>  'change_password',
		'uses'  =>  'UserController@changePasswordPage'
	));

	Route::post('/post-change-password', array(  
		'as'    =>  'post_change_password_form',
		'uses'  =>  'UserController@changePasswordFunc'
	));

	Route::get('/manage-users', array( //for admin
		'as'    =>  'manage_users',
		'uses'  =>  'UserController@displayUsers'
	));

	Route::get('/update-user-info/{user_id}', [
		'as' => 'update_user_info',
		'uses' => 'UserController@userEdit'
	]);

	Route::post('/change-user-status', array(  
		'as'    =>  'change_user_status',
		'uses'  =>  'UserController@changeUserStatus'
	));

	Route::post('/change-user-pass', array(  
		'as'    =>  'change_user_pass',
		'uses'  =>  'UserController@changeUserPassword'
	));

	Route::get('billing/subscriptions','BillingController@getSubscriptions');
	Route::post('billing/cancel-subscription','BillingController@postCancelSubscription');
	Route::post('billing/renew-subscription','BillingController@postRenewSubscription');

	Route::get('billing/invoices','BillingController@getInvoices');
	Route::get('billing/invoice/{id}','BillingController@getInvoicePdf');
	Route::get('billing/info','BillingController@getBillingInfo');



// Region manage routes

	Route::get('/manage-regions', array( //for admin
		'as'    =>  'manage_regions',
		'uses'  =>  'RegionController@displayRegions'
	));

	Route::get('/edit-region-info/{region_id}', [
		'as' => 'edit_region_info',
		'uses' => 'RegionController@regionEdit'
	]);

	Route::post('/edit-region-info', [
		'as' => 'update_region',
		'uses' => 'RegionController@regionUpdate'
	]);

	Route::delete('/delete-region/{region_id}/delete', [
		'as' => 'delete_region',
		'uses' => 'RegionController@deleteRegion'
	]);

	Route::get('/add-new-region', array( 
		'as'    =>  'add_new_region',
		'uses'  =>  'RegionController@addRegionPage'
	));

	Route::post('/add-new-region', array( 
		'as'    =>  'add_region',
		'uses'  =>  'RegionController@insertRegion'
	));



});

/// Disable csrf token for these routes..webhooks.. 2 ways (1 :exclude URIs by defining their routes outside of the web middleware   and
														//  2 :by adding the URIs to the $except property of the VerifyCsrfToken middleware:  )

Route::post('/recurly-webhooks', [
	'as' => 'recurly-webhooks',
	'uses' => 'WebHooksController@recurlyWebhooks'
]);
