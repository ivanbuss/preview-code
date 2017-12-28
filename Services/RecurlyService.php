<?php

namespace App\Services;

use App\Models\PurchasePlans;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Recurly_Plan;
use Recurly_Client;
use Recurly_Account;
use Recurly_Subscription;
use Recurly_Base;
use Recurly_BillingInfo;
use Recurly_AccountBalance;
use Recurly_ValidationError;
use Recurly_NotFoundError;

class RecurlyService {

    public function __construct() {
        $this->init();
    }

    protected function init() {
        Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');
    }

    public function createPlan($data) {
        $plan_price = $this->getPlanPrice($data['price'], $data['billing_type']);
        $plan_price_in_dollars = $plan_price * 100;

        $setup_fee_in_cents = 0;
        if (isset($data['setup_fee'])) $setup_fee_in_cents = $data['setup_fee'] * 100;

        $duration_array = explode("_", $data['duration']);
        $plan_interval_length = $duration_array[0];
        $plan_interval_unit = $duration_array[1];

        try {
            $createPlan = new Recurly_Plan();
            $createPlan->plan_code = $data['plan_code'];
            $createPlan->name = $data['plan_name'];
            $createPlan->description = $data['plan_description'];
            $createPlan->unit_amount_in_cents->addCurrency('USD', $plan_price_in_dollars); // USD 00.00 month
            if ($setup_fee_in_cents) $createPlan->setup_fee_in_cents->addCurrency('USD', $setup_fee_in_cents);
                else $createPlan->setup_fee_in_cents->addCurrency('USD', 0000); // USD 00.00 setup fee
            $createPlan->plan_interval_length = $plan_interval_length;
            $createPlan->plan_interval_unit = $plan_interval_unit;
            $createPlan->create();

            return ['success'=>TRUE, 'plan'=>$createPlan];
        }
        catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return ['success'=>FALSE, 'error'=>$error_message];
        }
    }

    public function updatePlan($code, array $data) {
        $duration_array = explode("_", $data['duration']);
        $plan_interval_length = $duration_array[0];
        $plan_interval_unit   = $duration_array[1];

        $plan_price = $this->getPlanPrice($data['price'], $data['billing_type']);
        $plan_price_in_dollars = $plan_price * 100;

        $setup_fee_in_cents = 0;
        if (isset($data['setup_fee'])) $setup_fee_in_cents = $data['setup_fee'] * 100;

        try {
            $updatePlan = Recurly_Plan::get($code);
            $updatePlan->plan_code = $data['plan_code'];
            $updatePlan->name = $data['plan_name'];
            $updatePlan->description = $data['plan_description'];
            $updatePlan->unit_amount_in_cents->addCurrency('USD', $plan_price_in_dollars); // USD 00.00 month
            $updatePlan->plan_interval_length = $plan_interval_length;
            $updatePlan->plan_interval_unit = $plan_interval_unit;
            if ($setup_fee_in_cents) $updatePlan->setup_fee_in_cents->addCurrency('USD', $setup_fee_in_cents);
                else $updatePlan->setup_fee_in_cents->addCurrency('USD', 00); // USD 00.00 setup fee
            $updatePlan->update();

            return ['success'=>TRUE, 'plan'=>$updatePlan];
        }
        catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return ['success'=>FALSE, 'error'=>$error_message];
        }

    }

    public function deletePlanByCode($plan_code) {
        $response = $this->getPlanByCode($plan_code);
        if ($response['success']) {
            $plan = $response['plan'];
            $plan->delete();
            return $plan;
        }
        //$plan = Recurly_Plan::get($plan_code);
        //$plan->delete();
    }

    public function getPlanByCode($plan_code) {
        try {
            $plan = Recurly_Plan::get($plan_code);
            return ['success'=>TRUE, 'plan'=>$plan];
        } catch (Recurly_NotFoundError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return ['success'=>FALSE, 'error'=>$error_message];
        }
    }

    public function getPlanPrice($price, $billing_type) {
        if ($billing_type == 'trial') {
            $plan_price = 0;
        } else {
            $plan_price = $price;
        }

        return $plan_price;
    }

    public function getAccount(User $user) {
        try {
            $account = Recurly_Account::get($user->user_identifier);
        } catch (Recurly_NotFoundError $e) {
            //print "Account not found.\n";
            $account = null;
        }
        return $account;
    }

    public function getBillingInfo(User $user) {
        try {
            $billing_info = Recurly_BillingInfo::get($user->user_identifier);
        } catch (Recurly_NotFoundError $e) {
            $billing_info = null;
        }
        return $billing_info;
    }

    public function updateBillingInfo($data, User $user) {
        $billing_info = $this->getBillingInfo($user);
        if ($billing_info) {
            try {
                $billing_info->token_id = $data['recurly-token'];
                $billing_info->update();
            } catch (Recurly_ValidationError $e) {
                // The data or card are invalid
                return FALSE;
            }
            return $billing_info;
        } else {
            try {
                $billing_info = new Recurly_BillingInfo();
                $billing_info->account_code = $user->user_identifier;
                $billing_info->token_id = $data['recurly-token']; // From Recurly.js
                $billing_info->create();
                return $billing_info;
            } catch (Recurly_NotFoundError $e) {
                return FALSE;
            }
        }
        return FALSE;
    }

    /**
     * @param $accountCode
     * @param null $params
     * @param null $client
     * @return mixed|null
     */
    public function getInvoicesForAccount($accountCode, $params = null, $client = null) {
        try {
            $invoices = \Recurly_InvoiceList::getForAccount($accountCode, $params, $client);
            return $invoices;
        } catch (Recurly_NotFoundError $e) {
            return [];
        }
    }

    public function lookupInvoice($number) {
        try {
            $invoice = \Recurly_Invoice::get($number);
            return $invoice;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function createSubscription($plan_code, $account, $data, $billing_info = null, $coupon_code = null) {
        try {
            $subscription = new Recurly_Subscription();
            $subscription->plan_code = $plan_code;
            $subscription->currency = 'USD';
            $subscription->account = $account;
            if ($coupon_code) $subscription->coupon_code = $coupon_code;

            if ($billing_info) {
                $subscription->account->billing_info = $billing_info;
            } else {
                $subscription->account->billing_info = new Recurly_BillingInfo();
                if (isset($data['address1'])) $subscription->account->address1 = $data['address1'];
                if (isset($data['city'])) $subscription->account->city = $data['city'];
                if (isset($data['state'])) $subscription->account->state = $data['state'];
                if (isset($data['country'])) $subscription->account->country = $data['country'];
                if (isset($data['phone'])) $subscription->account->phone = $data['phone'];
                if (isset($data['postal_code'])) $subscription->account->zip = $data['postal_code'];
                if (isset($data['recurly-token'])) $subscription->account->billing_info->token_id = $data['recurly-token'];
                if (isset($data['vat_number'])) {
                    $subscription->account->vat_number = $data['vat_number'];
                }
            }
            $subscription->create();
            return ['success'=>TRUE, 'subscription'=>$subscription];
        } catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return ['success'=>FALSE, 'error'=>$error_message];
        }
    }

    public function updateSubscription($uuid, $plan_code, $account, $data, $billing_info = null, $coupon_code = null) {
        try {
            $subscription = Recurly_Subscription::get($uuid);
            $subscription->plan_code = $plan_code;
            if ($coupon_code) $subscription->coupon_code = $coupon_code;
            if ($billing_info) {
                $subscription->account->billing_info = $billing_info;
            } else {
                if (isset($data['recurly-token'])) {
                    $billing_info = $account->billing_info->get();
                    $billing_info->token_id = $data['recurly-token'];
                    $billing_info->update();
                }
                
                if (isset($data['address1'])) $subscription->account->address1 = $data['address1'];
                if (isset($data['city'])) $subscription->account->city = $data['city'];
                if (isset($data['state'])) $subscription->account->state = $data['state'];
                if (isset($data['country'])) $subscription->account->country = $data['country'];
                if (isset($data['phone'])) $subscription->account->phone = $data['phone'];
                if (isset($data['postal_code'])) $subscription->account->zip = $data['postal_code'];
                if (isset($data['vat_number'])) $subscription->account->vat_number = $data['vat_number'];
            }

            $subscription->updateImmediately();
            return ['success'=>TRUE, 'subscription'=>$subscription];
        } catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
        } catch (Recurly_NotFoundError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
        }
        return ['success'=>FALSE, 'error'=>$error_message];
    }

    public function updateManualSubscription($uuid, $plan_code, $account) {
        try {
            $subscription = Recurly_Subscription::get($uuid);
            $subscription->plan_code = $plan_code;
            $subscription->collection_method = 'manual';

            $subscription->updateImmediately();
            return ['success'=>TRUE, 'subscription'=>$subscription];
        } catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
        } catch (Recurly_NotFoundError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
        }
        return ['success'=>FALSE, 'error'=>$error_message];
    }

    public function terminateSubscription($uuid, PurchasePlans $purchasePlan, User $user) {
        try {
            $subscription = Recurly_Subscription::get($uuid);
            $invoice = $subscription->invoice->get();
            if ($invoice && $invoice->state != 'collected') {
                $invoice->markFailed();
            }
            $subscription->terminateWithoutRefund();
            $this->cleanCache($user);

            $purchasePlan->status = $subscription->state;
            $purchasePlan->save();
            return ['success'=>TRUE];
        } catch (Recurly_NotFoundError $e) {
            $error_message = "Subscription Not Found: $e";
        } catch (Recurly_Error $e) {
            $error_message = "Subscription already terminated: $e";
        }
        return ['success'=>FALSE, 'error'=>$error_message];
    }

    public function createAccount(User $user) {
        try {
            $account = new Recurly_Account($user->user_identifier);
            $account->email = $user->email;
            $account->username = $user->username;
            $account->first_name = $user->first_name;
            $account->last_name = $user->last_name;
            $account->create();

            return ['success'=>TRUE, 'account'=>$account];
        } catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return ['success'=>FALSE, 'error'=>$error_message];
        }
    }

    public function getSubscription($uuid) {
        try {
            $subscription = \Recurly_Subscription::get($uuid);
            return $subscription;
        } catch (Recurly_ValidationError $e) {
            return FALSE;
        }
    }

    public function getUnusedFinds($uuid) {
        try {
            $subscription = Recurly_Subscription::get($uuid);
            $unit_amount_in_cents = $subscription->unit_amount_in_cents;

            $current_period_started_at = $subscription->current_period_started_at->getTimestamp();
            $current_period_ends_at = $subscription->current_period_ends_at->getTimestamp();
            $now = Carbon::now()->timestamp;
            $duration = $current_period_ends_at - $current_period_started_at;
            $usage_part = ($now - $current_period_started_at) / $duration;
            $unused_funds = $unit_amount_in_cents - ($unit_amount_in_cents * $usage_part);
            return round($unused_funds, 0, PHP_ROUND_HALF_DOWN);
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function getAccountBalance(User $user, $useCache = TRUE) {
        if (Cache::has('account_balance_'.$user->id) && $useCache) {
            $balance_amount = Cache::get('account_balance_'.$user->id);
        } else {
            try {
                $balance = Recurly_AccountBalance::get($user->user_identifier);
                if (isset($balance->balance_in_cents['USD'])) {
                    $balance_amount = $balance->balance_in_cents['USD']->amount_in_cents * (-1);
                    Cache::put('account_balance_'.$user->id, $balance_amount, 5);
                } else return 0;
            } catch (Recurly_NotFoundError $e) {
                return FALSE;
            } catch (Recurly_ValidationError $e) {
                return FALSE;
            } catch (\Recurly_ConnectionError $e) {
                return FALSE;
            }
        }
        return $balance_amount;
    }

    public function getPostDueAmount(User $user, $useCache = TRUE) {
        if (Cache::has('post_due_'.$user->id) && $useCache) {
            $amount = Cache::get('post_due_'.$user->id);
        } else {
            $invoices = $this->getInvoicesForAccount($user->user_identifier, ['state'=>'past_due']);
            $amount = 0;
            foreach($invoices as $invoice) {
                if ($invoice->state != 'collected' && $invoice->state != 'failed') {
                    $amount = $amount + $invoice->total_in_cents;
                }
            }
            Cache::put('post_due_'.$user->id, $amount, 5);
        }
        return $amount;
    }

    public function getCoupon($coupon_code) {
        try {
            $coupon = \Recurly_Coupon::get($coupon_code);
            return $coupon;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function getAdjustmentList(User $user) {
        try {
            $adjustments = \Recurly_AdjustmentList::get($user->user_identifier);
            return $adjustments;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }


    public function createManualSubscription($plan_code, $account) {
        try {
            $subscription = new Recurly_Subscription();
            $subscription->plan_code = $plan_code;
            $subscription->currency = 'USD';
            $subscription->account = $account;
            $subscription->collection_method = 'manual';

            $subscription->create();
            return ['success'=>TRUE, 'subscription'=>$subscription];
        } catch (Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return ['success'=>FALSE, 'error'=>$error_message];
        }
    }

    public function markInvoiceAsPaid($invoice_id) {
        try {
            $invoice = \Recurly_Invoice::get($invoice_id);
            $invoice->markSuccessful();

            return $invoice;
        } catch (Recurly_ValidationError $e) {
            return FALSE;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function createCredit($amount, $description, User $user) {
        try {
            $credit = new \Recurly_Adjustment();
            $credit->account_code = $user->user_identifier;
            $credit->description = $description;
            $credit->unit_amount_in_cents = (-1 * $amount); // Negative $20.00.
            $credit->currency = 'USD';
            $credit->quantity = 1;
            $credit->create();

            return TRUE;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function createCharge($amount, $description, User $user, $accounting_code = null) {
        try {
            $charge = new \Recurly_Adjustment();
            $charge->account_code = $user->user_identifier;
            $charge->description = $description;
            $charge->unit_amount_in_cents = $amount; // $50.00
            $charge->currency = 'USD';
            $charge->quantity = 1;
            $charge->accounting_code = $accounting_code;
            $charge->tax_exempt = false;
            $charge->create();

            return TRUE;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function invoicePendingCharges(User $user) {
        try {
            $invoice = \Recurly_Invoice::invoicePendingCharges($user->user_identifier);
            return $invoice;
        } catch (Recurly_ValidationError $e) {
            return FALSE;
        } catch (Recurly_NotFoundError $e) {
            return FALSE;
        }
    }

    public function cleanCache($user) {
        Cache::forget('post_due_'.$user->id);
        Cache::forget('account_balance_'.$user->id);
    }

    public function payInvoicesByCredits(User $user) {
        $account_balance = $this->getAccountBalance($user, FALSE);
        $past_due = $this->getPostDueAmount($user, FALSE);
        $credit_value = $account_balance + $past_due;
        $invoices = $this->getInvoicesForAccount($user->user_identifier);
        foreach ($invoices as $invoice) {
            if ($invoice->state == 'past_due' && $credit_value >= $invoice->total_in_cents) {
                $description = 'Charge for invoice ID:'.$invoice->invoice_number;
                $this->createCharge($invoice->total_in_cents, $description, $user);
                $this->markInvoiceAsPaid($invoice->invoice_number);
                $this->invoicePendingCharges($user);
                $credit_value = $credit_value - $invoice->total_in_cents;
            }
        }
    }

    public function getCustomerSubscriptions(User $user, $params = []) {
        try {
            $subscriptions = \Recurly_SubscriptionList::getForAccount($user->user_identifier, $params);
            return $subscriptions;
        } catch (Recurly_NotFoundError $e) {
            return [];
        }
    }

}
