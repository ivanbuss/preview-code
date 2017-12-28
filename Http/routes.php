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
$is_admin = (isset($_SERVER['SERVER_NAME']) && (($_SERVER['SERVER_NAME'] == 'psadmin.vpnstars.com') || $_SERVER['SERVER_NAME'] == 'proxystars')) ? TRUE : FALSE;
Route::group(['middleware' => ['web']], function () {

	Route::auth();
	Route::get('free', 'Auth\AuthController@getRegister');

	Route::get('account-activate/{code}', array(
		'as' => 'account-activate',
		'uses'=>'Auth\AuthController@getActivate'
	));

	Route::get('update-email-address/{email_reset_code}/{new_email}', array(
		'as' => 'update_email_address',
		'uses'=>'UserController@updateEmailAddress'
	));

	Route::get('ajax/states', 'BillingController@getStates');
});

Route::group(['middleware' => ['web']], function () {
	Route::get('/recurly-payment-modal/{plan_code}', 'RecurlyBuyformController@getRecurlyPaymentModal');

	Route::get('list/products/{type}', array(
		'as'    =>  'recurly_products_new_guest',
		'uses'  =>  'RecurlyController@recurlyProductsTypeNewGuest'
	));
	Route::get('list/products', array(
		'as'    =>  'recurly_products_new_guest',
		'uses'  =>  'RecurlyController@recurlyProductsNewGuest'
	));
	Route::post('list/products', [
		'as' => 'post_recurly_buy_form',
		'uses' => 'RecurlyBuyformController@postRecurlyBuyGuestForm'
	]);

	Route::get('/get-products-for-features', array(  //for new product page - ajax request
		'as'    =>  'get_products_for_features',
		'uses'  =>  'RecurlyController@getProductsFiltered'
	));

	Route::get('/get-products-units', array(  //for products unit select - ajax request
		'uses'  =>  'RecurlyController@getUnitAndLocationSelect'
	));

	Route::get('/get-level2-cats-ajax', array(  //for new product page - ajax request
		'as'    =>  'get_level_2_cats_ajax',
		'uses'  =>  'RecurlyController@getCategoryChildrenLevel2'
	));

	Route::get('/get-level3-cats-ajax', array(  //for new product page - ajax request
		'as'    =>  'get_level_3_cats_ajax',
		'uses'  =>  'RecurlyController@getCategoryChildrenLevel3'
	));

	Route::get('/load_subcategories', 'RecurlyController@getSubcategories');
});

Route::group(['middleware' => ['web','auth']], function () {
    Route::get('/', 'HomeController@getIndex');

    Route::get('/dashboard', [
        'as' => 'dashboard',
        'uses'=>'HomeController@getDashboard'
    ]);

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
			$hash = hash_hmac('md5', $to_be_hashed, env('FRESHDESK_SHARED_SECRET'));
			$url= env('FRESHDESK_BASE_URL')."/login/sso/?name=".urlencode($strName)."&email=".urlencode($strEmail)."&timestamp=".$timestamp."&hash=".$hash;
			return $url;
		}
		header("Location: ".getSSOUrl(Auth::user()->first_name.' '.Auth::user()->last_name ,Auth::user()->email));
		dd('trying to login freshdesk');
	}]);


	//login recurly with out iframe
    /*
	Route::get('/recurly-login', [
        'as' => 'recurly_login',
        'uses' => 'RecurlyController@getLogin',
    ]);
	*/
	/*
    Route::get('/recurly-iframe', [
        'as' => 'recurly_iframe',
        'uses' => 'RecurlyController@getIframe',
    ]);
	*/
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

	Route::post('/update-recurly-category/{category}', array(
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


	Route::get('/get_duration_for_plan', array(   //for admin - ajax request
		'as'    =>  'get_duration_for_plan',
		'uses'  =>  'RecurlyController@getDurationForPlan'
	));

	Route::get('product-details/{uuid}/{plan_id}', 'RecurlyController@productDetailForCustomers');

	Route::get('product-details/{uuid}/{plan_id}/fix', 'RecurlyController@getFixErrorServices');

	Route::get('vpn-users', 'VpnUsersController@getUsersList');
    Route::get('vpn-users/data', 'VpnUsersController@getUsersListTableData');
	Route::post('vpn-users/create', 'VpnUsersController@postCreate');
    Route::post('vpn-users/{vpnUser}/status', 'VpnUsersController@postStatusUpdate');
    Route::get('vpn-users/{vpnUser}/password', 'VpnUsersController@getPasswordUpdate');
    Route::post('vpn-users/{vpnUser}/password', 'VpnUsersController@postPasswordUpdate');
    Route::delete('vpn-users/{vpnUser}/delete', 'VpnUsersController@delete');
	Route::get('vpn-users/{vpnUserRel}/locations', 'VpnUsersController@getVPNLocations');
	Route::get('vpn-users/{vpnUserRel}/download/{protocol}/{location}', 'VpnUsersController@getDownloadLocationProfile');
	Route::get('vpn-users/{vpnUserRel}/download/{protocol}', 'VpnUsersController@getDownloadProfile');

	Route::get('product-details/{uuid}/{plan_id}/user/assign', 'VpnUsersController@getAssignUser');
	Route::post('product-details/{uuid}/{plan_id}/user/assign', 'VpnUsersController@postAssignUser');

	Route::delete('product-details/{uuid}/{plan_id}/user/{serversuser}/remove', 'VpnUsersController@postRemoveUser');

	Route::post('product-details/{uuid}/{plan_id}/router-update/flash', 'RouterController@postFlashRouter');


	Route::get('product-details/{uuid}/{plan_id}/upgrade', 'RecurlyController@recurlyProductUpgrade');
    //Route::post('product-details/{uuid}/upgrade', 'VpnServerController@postUpgrade');

	Route::post('product-details/{uuid}/{plan_id}/upgrade/form', 'RecurlyBuyformController@postUpgradeRecurlyBuyForm');
	Route::post('product-details/{uuid}/{plan_id}/upgrade/api', 'RecurlyBuyformController@postUpgradeRecurlyBuyFormAPI');
	Route::post('product-details/{uuid}/{plan_id}/upgrade/credit', 'RecurlyBuyformController@postUpgradeRecurlyBuyCreditsForm');

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

	Route::post('/post-recurly-buy-credits-api', 'RecurlyBuyformController@postRecurlyBuyCreditsForm');

	Route::post('/post-recurly-buy-form', [
		'as' => 'post_recurly_buy_form', 'uses' => 'RecurlyBuyformController@postRecurlyBuyForm'
	]);
	Route::post('/renew_recurly_form', [
		'as' => 'renew_recurly_form', 'uses' => 'RecurlyBuyformController@renewRecurlyForm'
	]);
	Route::post('/renew_recurly_form_api', [
		'as' => 'renew_recurly_form_api', 'uses' => 'RecurlyBuyformController@renewRecurlyFormAPI'
	]);

	Route::post('/renew_recurly_form_credit', 'RecurlyBuyformController@postRenewRecurlyBuyCreditsForm');

	Route::post('/post_paypal_recurly_buy_form', [
		'as' => 'post_paypal_recurly_buy_form', 'uses' => 'RecurlyBuyformController@postPayPalRecurlyBuyForm'
	]);
	Route::post('/request-authorized-ips/{uid}/{plan_id}', [
		'as' => 'request_authorized_ips', 'uses' => 'RecurlyController@postAuthorizedIpsRequest'
	]);
	
	Route::post('/update-custom-plan/{plan_id}', [
		'as' => 'update_params_custom_plan', 'uses' => 'RecurlyController@updateParamsCustomPlan'
	]);

	Route::get('/thankyou', array(
		'as' => 'thankyou',
		'uses'=>'UserController@showThankYou'
	));

	Route::get('/products/{type}', array(
		'as'    =>  'recurly_products_new',
		'uses'  =>  'RecurlyController@recurlyProductsTypeNew'
	));

	Route::get('/products', array(
		'as'    =>  'recurly_products_new',
		'uses'  =>  'RecurlyController@recurlyProductsNew'
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

	Route::post('update-user-info/{user}/trial-reactivate', 'UserController@postTrialReactivate');
	Route::post('update-user-info/{user}/vpn-trial-reactivate', 'UserController@postVpnTrialReactivate');

	Route::post('/change-user-pass', array(  
		'as'    =>  'change_user_pass',
		'uses'  =>  'UserController@changeUserPassword'
	));

	Route::get('billing/subscriptions','BillingController@getSubscriptions');
	Route::post('billing/cancel-subscription','BillingController@postCancelSubscription');
	Route::post('billing/renew-subscription','BillingController@postRenewSubscription');

	Route::get('billing/invoices','BillingController@getInvoices');
	//Route::get('billing/invoice/{id}', 'BillingController@getInvoiceView');
	Route::get('billing/invoice/{id}/download','BillingController@getInvoicePdf');
	Route::post('billing/invoice/{id}/pay-credits', 'BillingController@postPayInvoiceByCredits');
	Route::get('billing/info','BillingController@getBillingInfo');
	Route::post('billing/info','BillingController@postBillingInfo');

	Route::get('billing/paypal','PayPalController@getPaypalPage');
	Route::post('billing/paypal', 'PayPalController@postPaypalExpressCheckout');

	Route::get('billing/paypal/success', 'PayPalController@getPaypalSuccess');
	Route::get('billing/paypal/cancel', 'PayPalController@getPaypalCancel');

	Route::get('billing/paypal/payment/get','PayPalController@getPayment');

	Route::get('billing/paypal/subscription/test','PayPalController@getSubscriptionTest');
    Route::get('billing/paypal/subscription/test/success','PayPalController@getSubscriptionTestExecute');

	Route::get('billing/data/credit/update', 'BillingController@getUpdateCredit');
	Route::get('billing/data/past-due/update', 'BillingController@getUpdatePastDue');
	Route::post('billing/data/credits/check', 'BillingController@postCheckCredit');


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

	Route::get('/router-register', 'RouterController@getRegistration');
	Route::post('/router-register', 'RouterController@postRegisterRouter');

	Route::get('/router-register/success', 'RouterController@showThankYou');
	Route::get('/router-register/error', 'RouterController@showErrorPage');

	Route::post('product-details/{uuid}/{plan_id}/router-update/reset', 'RouterController@postResetRouter');

});

if ($is_admin) {
	Route::group(['middleware' => ['web', 'auth', 'admin']], function () {
		Route::get('settings', 'SettingsController@getSettings');
		Route::post('settings', 'SettingsController@postUpdate');
		Route::get('settings/category/form', 'SettingsController@getCategoriesFormElement');
		Route::get('settings/category/{category}/update-prices', 'Admin\ProductsController@updatePrices');

		Route::get('admin/subscriptions', 'SubscriptionsController@getSubscriptions');
		Route::get('admin/subscriptions/data', 'SubscriptionsController@getSubscriptionsTableData');

		Route::get('admin/subscriptions/{subscription}/view', 'SubscriptionsController@getSubscriptionView');
		Route::post('admin/subscriptions/{subscription}/activate', 'SubscriptionsController@postSubscriptionActivate');
		Route::post('admin/subscriptions/{subscription}/enable', 'SubscriptionsController@postSubscriptionEnable');

		Route::post('admin/subscriptions/{subscription}/shipping/update', 'SubscriptionsController@postUpdateShippingData');
		Route::post('admin/subscriptions/{subscription}/shipping/status-update', 'SubscriptionsController@postUpdateShippingStatus');

		Route::get('admin/routers/queue', 'Admin\RouterQueueController@getRouters');
		Route::get('admin/routers/queue/data', 'Admin\RouterQueueController@getRoutersTableData');

		Route::get('admin/routers/queue/{router}/view', 'Admin\RouterQueueController@getRoutersView');
		Route::post('admin/routers/queue/{router}/provision', 'Admin\RouterQueueController@postRoutersProvision');

		Route::get('admin/payments', 'Admin\PaymentsController@getPayments');
		Route::get('admin/payments/data', 'Admin\PaymentsController@getPaymentsTableData');

		Route::get('admin/cities', 'Admin\CitiesController@getList');
		Route::get('admin/cities/data', 'Admin\CitiesController@getListTableData');
		Route::get('admin/cities/create', 'Admin\CitiesController@getCreate');
		Route::post('admin/cities/create', 'Admin\CitiesController@postCreate');
		Route::get('admin/cities/{city}/edit', 'Admin\CitiesController@getEdit');
		Route::post('admin/cities/{city}/update', 'Admin\CitiesController@postUpdate');
		Route::delete('admin/cities/{city}/delete', 'Admin\CitiesController@delete');

		Route::get('manage-users/data', 'UserController@displayUsersTableData');

		Route::get('admin/manage-products', 'Admin\ProductsController@recurlyProducts');
		Route::get('admin/manage-products/data', 'Admin\ProductsController@recurlyProductsTableData');

		Route::get('admin/create-product', 'Admin\ProductsController@getRecurlyProductCreateFormSelect');
		Route::post('admin/create-product', 'Admin\ProductsController@postRecurlyProductCreateFormSelect');
		Route::get('admin/create-product/{type}', 'Admin\ProductsController@getRecurlyProductCreateForm');
		Route::post('admin/create-product/{type}', 'Admin\ProductsController@postCreateRecurlyProduct');


		Route::get('admin/update-product/{recurly_plan}', 'Admin\ProductsController@getRecurlyProductUpdateForm');
		Route::post('admin/update-product/{recurly_plan}/update', 'Admin\ProductsController@postRecurlyProductUpdateForm');
		Route::get('admin/copy-product/{recurly_plan}', 'Admin\ProductsController@getRecurlyProductCopyForm');

		Route::delete('admin/delete-recurly-product/{plan_id}/delete', 'Admin\ProductsController@deleteRecurlyProductForm');

		Route::get('admin/log', 'Admin\ErrorLogController@getLog');
		Route::get('admin/log/data', 'Admin\ErrorLogController@logTableData');
		Route::get('admin/log/{item}/view', 'Admin\ErrorLogController@getShow');
		Route::delete('admin/log/{item}/delete', 'Admin\ErrorLogController@delete');
	});
}
/// Disable csrf token for these routes..webhooks.. 2 ways (1 :exclude URIs by defining their routes outside of the web middleware   and
														//  2 :by adding the URIs to the $except property of the VerifyCsrfToken middleware:  )

Route::any('/recurly-webhooks', [
	'as' => 'recurly-webhooks',
	'uses' => 'WebHooksController@recurlyWebhooks',
	'nocsrf' => true,
]);

Route::get('products-feed', 'RecurlyController@getFeed');

/*
Route::get('api/creat-vpn-service/{user}', ['as'=>'api_test', function (\App\User $user) {
	$service = new \App\Services\StoreVPNService();
    $response = $service->createVPNService($user);
    p($response); exit;
}]);
*/