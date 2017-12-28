<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use App\Models\RecurlyCategories;
use App\Models\RecurlyProducts;
use App\Models\SettingsModel;
use App\Services\Settings;
use App\User;
use Illuminate\Http\Request;
use Carbon;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{

    protected $settings;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(Settings $settings) {
        $this->settings = $settings;
        $this->middleware('auth');
    }

    /**
     * Show the admin settings page.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSettings() {
        $settings = $this->settings->getAll();
        $categories = RecurlyCategories::has('products')->where('parent_category_id', '!=', 0)->where('trial', '!=', 1)
            ->with('parent', 'parent.parent', 'parent.parent.parent')->get();
        $cat_list = [];
        foreach($categories as $category) {
            $name = $category->name;
            if ($category->parent) {
                $name = $category->parent->name . ' > ' . $name;
                if ($category->parent->parent) {
                    $name = $category->parent->parent->name . ' > ' . $name;
                    if ($category->parent->parent->parent) {
                        $name = $category->parent->parent->parent->name . ' > ' . $name;
                    }
                }
            }
            $cat_list[$category->id] = $name;
        }
        $discount_settings = SettingsModel::where('name', 'LIKE', 'discounts_%')->get();
        $discounts = [];
        foreach($discount_settings as $discount_setting) {
            $name = explode('_', $discount_setting->name);
            if (isset($name[1]) && is_numeric($name[1])) {
                $discounts[$name[1]] = unserialize($discount_setting->value);
                $discounts[$name[1]]['cat_id'] = $name[1];
            }
        }

        $proxy_trial_products = RecurlyProducts::where('billing_type', 'trial')->where('plan_type', '!=', 'vpn_dedicated')
            ->orderBy('plan_code', 'asc')->lists('plan_code', 'plan_code');
        $vpn_free_products = RecurlyProducts::where('billing_type', 'trial')->where('plan_type', '=', 'vpn_dedicated')
            ->where('city', 5)
            ->orderBy('plan_code', 'asc')->lists('plan_code', 'plan_code');
        $vpn_trial_products = RecurlyProducts::where('billing_type', 'trial')->where('plan_type', '=', 'vpn_dedicated')
            ->where('city', '!=', 5)
            ->orderBy('plan_code', 'asc')->lists('plan_code', 'plan_code');
        $router_products = RecurlyProducts::where('billing_type', 'duration')->where('plan_type', '=', 'router')
          ->lists('plan_code', 'plan_code');

        return view('recurly.admin.settings.view', [
            'settings'=>$settings,
            'proxy_trial_products'=>$proxy_trial_products,
            'vpn_free_products'=>$vpn_free_products,
            'vpn_trial_products'=>$vpn_trial_products,
            'router_products'=>$router_products,
            'categories'=>$cat_list,
            'discount_settings'=>$discounts
        ]);
    }

    public function postUpdate(Request $request) {
        $validator = $this->validator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $this->saveSettings($request);

        return redirect()->action('SettingsController@getSettings')->with('success', 'Settings have been changed.');
    }

    public function getCategoriesFormElement(Request $request) {
        if ($request->has('index')) $index = $request->get('index');
            else $index = 0;
        $index++;
        $categories = RecurlyCategories::where('parent_category_id', '!=', 0)->where('trial', '!=', 1)
            ->with('parent', 'parent.parent', 'parent.parent.parent')->get();
        $cat_list = [];
        foreach($categories as $category) {
            $name = $category->name;
            if ($category->parent) {
                $name = $category->parent->name . ' > ' . $name;
                if ($category->parent->parent) {
                    $name = $category->parent->parent->name . ' > ' . $name;
                    if ($category->parent->parent->parent) {
                        $name = $category->parent->parent->parent->name . ' > ' . $name;
                    }
                }
            }
            $cat_list[$category->id] = $name;
        }
        return view('recurly.admin.settings.view_category_form_element', ['index'=>$index, 'categories'=>$cat_list])->render();
    }

    protected function saveSettings(Request $request) {
        $this->settings->set('billing_descriptor', $request->get('billing_descriptor'));
        $this->settings->set('proxy_trial_product', $request->get('proxy_trial_product'));
        $this->settings->set('vpn_free_product', $request->get('vpn_free_product'));
        $this->settings->set('vpn_trial_product', $request->get('vpn_trial_product'));
        $this->settings->set('offline_router_product', $request->get('offline_router_product'));
        $this->settings->set('show_empty_cats', $request->get('show_empty_cats'));
        $this->settings->set('debug_mode', $request->get('debug_mode'));
        $this->settings->set('send_debug_emails', $request->get('send_debug_emails'));
        $this->settings->set('debug_email_recipients', $request->get('debug_email_recipients'));
        $this->settings->set('send_debug_emails_method', $request->get('send_debug_emails_method'));
        $this->settings->set('enable_vat', $request->get('enable_vat'));
        $this->settings->set('terms', $request->get('terms'));

        $this->settings->set('mail_use_default', $request->get('mail_use_default'));
        $this->settings->set('mail_driver', $request->get('mail_driver'));
        $this->settings->set('mail_host', $request->get('mail_host'));
        $this->settings->set('mail_port', $request->get('mail_port'));
        $this->settings->set('mail_username', $request->get('mail_username'));
        $this->settings->set('mail_password', $request->get('mail_password'));
        $this->settings->set('mail_encryption', $request->get('mail_encryption'));

        $this->settings->set('email_reset_password', $request->get('email_reset_password'));
        $this->settings->set('email_activate_account', $request->get('email_activate_account'));
        $this->settings->set('email_change_email', $request->get('email_change_email'));
        $this->settings->set('email_error_report', $request->get('email_error_report'));
        $this->settings->set('email_router_reset', $request->get('email_router_reset'));

        $discount3 = $request->get('category_discount_3m');
        $discount6 = $request->get('category_discount_6m');
        $discount12 = $request->get('category_discount_12m');
        foreach($request->get('category') as $key=>$category_id) {
            $category_data = [
                3 => (isset($discount3[$key]) && !empty($discount3[$key])) ? $discount3[$key] : 0,
                6 => (isset($discount6[$key]) && !empty($discount6[$key])) ? $discount6[$key] : 0,
                12 => (isset($discount12[$key]) && !empty($discount12[$key])) ? $discount12[$key] : 0,
            ];
            $this->settings->set('discounts_'.$category_id, serialize($category_data));
        }
    }

    public function validator(array $data) {
        return Validator::make($data, [
            'debug_mode' => 'boolean',
            'send_debug_emails' => 'boolean',
            'debug_email_recipients' => 'string',
            'billing_descriptor' => 'string',
            'send_debug_emails_method' => 'in:laravel,zendesk',
            'proxy_trial_product' => 'exists:recurly_products,plan_code,billing_type,trial,plan_type,!vpn_dedicated',
            'vpn_free_product' => 'exists:recurly_products,plan_code,billing_type,trial,plan_type,vpn_dedicated,city,5',
            'vpn_trial_product' => 'exists:recurly_products,plan_code,billing_type,trial,plan_type,vpn_dedicated,city,!5',
            'offline_router_product' => 'exists:recurly_products,plan_code,billing_type,duration,plan_type,router',
            'show_empty_cats' => 'boolean',
            'enable_vat' => 'boolean',
            'terms' => 'string',
            'mail_use_default' => 'boolean',
            'mail_driver' => 'string',
            'mail_host' => 'string',
            'mail_port' => 'integer',
            'mail_username' => 'string',
            'mail_password' => 'string',
            'mail_encryption' => 'string',
            'email_reset_password' => 'string',
            'email_activate_account' => 'string',
            'email_change_email' => 'string',
            'email_error_report' => 'string',
            'email_router_reset' => 'string'
        ]);
    }

}
