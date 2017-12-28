<?php

namespace App\Http\Controllers;

use App\Models\PurchasePlans;
use App\Models\SubscriptionCancelReason;
use App\Services\Settings;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Recurly_Client;
use Recurly_Base;
use Recurly_SubscriptionList;
use Recurly_Subscription;
use Recurly_Invoice;
use Recurly_Account;
use Illuminate\Http\Response;
use App\Services\RecurlyService;

class BillingController extends Controller
{

    protected $recurlyService;
    protected $settings;

    function __construct(RecurlyService $recurlyService, Settings $settings) {
        $this->recurlyService = $recurlyService;
        $this->settings = $settings;
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getSubscriptions(Request $request)
    {
        $subscriptions = $this->recurlyService->getCustomerSubscriptions($request->user());
        $purchasePlans = PurchasePlans::with('plan')->where('customer_id', $request->user()->id)->get();
        foreach($purchasePlans as $purchasePlan) {
            $plans[$purchasePlan->uuid] = $purchasePlan;
        }

        return view('billing.subscriptions', ['subscriptions'=>$subscriptions, 'plans'=>$plans]);
    }

    /**
     * @param $accountCode
     * @param null $params
     * @param null $client
     * @return Recurly_SubscriptionList
     */
    public static function getSubscriptionForAccount($accountCode, $params = null, $client = null) {
        return Recurly_Base::_get(Recurly_Client::PATH_ACCOUNTS . '/' . rawurlencode($accountCode) . Recurly_Client::PATH_SUBSCRIPTIONS, $params);
    }

    /**
     * Cancel a subscription
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function postCancelSubscription(Request $request)
    {
        $validator = $this->cancelReasonValidator($request->all(), $request->user());
        if ($validator->fails()) {
            $this->throwValidationException(
              $request, $validator
            );
        }

        $success = FALSE;
        $uuid = $request->get('uuid');
        $purchasePlan = PurchasePlans::where('uuid', $uuid)->where('customer_id', $request->user()->id)->firstOrFail();

        Recurly_Client::$subdomain = env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey = env('RECURLY_APIKEY');
        try {
            $subscription = Recurly_Subscription::get($uuid);
            if ($purchasePlan->local_status == PurchasePlans::ACTIVE) {
                $subscription->cancel();
            } else {
                $terminated = FALSE;
                if ($subscription->invoice) {
                    $invoice = $subscription->invoice->get();
                    if ($invoice && $invoice->state != 'collected') {
                        $invoice->markFailed();
                        $subscription->terminateWithoutRefund();
                        $this->recurlyService->cleanCache($request->user());
                        $terminated = TRUE;
                    }
                }
                if (!$terminated) {
                    $subscription->cancel();
                }
            }
            $this->saveCancelReason($request->all(), $purchasePlan, $request->user());
            $message = "Your subscription has been cancelled.";
            $success = TRUE;
        } catch (\Recurly_NotFoundError $e) {
            $message = "Subscription Not Found $e";
        } catch (\Recurly_Error $e) {
            $message = "Subscription already canceled $e";
        }

        if ($request->ajax()) {
            return response()->json(['status' => true, 'message' => $message]);
        } else {
            if ($success) return redirect()->back()->with('success', $message);
                else return redirect()->back()->with('error', $message);
        }
    }

    /**
     * Renew a subscription
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function postRenewSubscription(Request $request)
    {
        $uuid = $request->get('uuid');
        $purchasePlan = PurchasePlans::where('uuid', $uuid)->where('customer_id', $request->user()->id)->firstOrFail();

        Recurly_Client::$subdomain = env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey = env('RECURLY_APIKEY');
        try {
            $subscription = Recurly_Subscription::get($uuid);
            $subscription->reactivate();
            $message = "Your subscription is reactivated.";
        } catch (\Recurly_NotFoundError $e) {
            $message = "Subscription Not Found";
        } catch (\Recurly_Error $e) {
            $message = "Subscription already reactivated";
        }
        return response()->json(['status' => true, 'message' => $message]);
    }



    /**
     * To Get the invoices For billing/invoices page
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function getInvoices(Request $request)
    {
        $account_balance = $this->recurlyService->getAccountBalance($request->user());
        $past_due = 0;
        $user_identifier = Auth::user()->user_identifier;
        $recurlyInvoices = $this->recurlyService->getInvoicesForAccount($user_identifier);

        $invoices = [];
        foreach($recurlyInvoices as $invoice) {
            if ($invoice->state != 'collected' && $invoice->state != 'failed') {
                $past_due = $past_due + $invoice->total_in_cents;
            }
            $invoices[$invoice->invoice_number]['invoice_number'] = $invoice->invoice_number;
            $count = count($invoice->line_items);
            $invoices[$invoice->invoice_number]['description'] = isset($invoice->line_items[$count-1]) ? $invoice->line_items[$count-1]->description : null;
            if (is_null($invoices[$invoice->invoice_number]['description'])) $invoices[$invoice->invoice_number]['description'] = $invoice->line_items[0]->description;
            if ($invoice->subscription) {
                $href = $invoice->subscription->getHref();
                $subscription_id = substr($href, strrpos($href, '/')+1);
                $invoices[$invoice->invoice_number]['subscription_id'] = $subscription_id;
            } else {
                $invoices[$invoice->invoice_number]['subscription_id'] = '';
            }
            $invoices[$invoice->invoice_number]['created_at'] = $invoice->created_at ? $invoice->created_at->format('M d Y') : null;
            $invoices[$invoice->invoice_number]['closed_at'] =  $invoice->closed_at ? $invoice->closed_at->format('M d Y') : null;
            $invoices[$invoice->invoice_number]['state'] = $invoice->state;
            $invoices[$invoice->invoice_number]['total_in_cents'] = number_format($invoice->total_in_cents / 100, 2, '.', '');
            $invoices[$invoice->invoice_number]['value'] = $invoice->total_in_cents;
        }
        $credit_value = $account_balance + $past_due;
        $selected_uuid = $request->get('uuid');
        return view('billing.invoices', ['invoices'=>$invoices, 'selected_uuid'=>$selected_uuid, 'credit_value'=>$credit_value/100, 'past_due'=>$past_due]);
    }

    public function postPayInvoiceByCredits(Request $request, $id) {
        $account_balance = $this->recurlyService->getAccountBalance($request->user(), FALSE);
        $past_due = $this->recurlyService->getPostDueAmount($request->user(), FALSE);
        $credit_value = $account_balance + $past_due;

        $invoices = $this->recurlyService->getInvoicesForAccount($request->user()->user_identifier);
        $selected_invoice = null;
        foreach($invoices as $invoice) {
            if ($invoice->invoice_number == $id) {
                $selected_invoice = $invoice;
                break;
            }
        }
        if (!$selected_invoice) abort(404);
        if ($credit_value >= $selected_invoice->total_in_cents) {
            $description = 'Charge for invoice ID:'.$selected_invoice->invoice_number;
            $this->recurlyService->createCharge($selected_invoice->total_in_cents, $description, $request->user());
            $this->recurlyService->markInvoiceAsPaid($selected_invoice->invoice_number);
            $this->recurlyService->invoicePendingCharges($request->user());
        } else {
            return Redirect::back()->withErrors([
              'error' => '<strong>Credits are less then the invoice amount!</strong> Please add funds to your account with PayPal or add a Credit Card'
            ]);
        }

        return Redirect::back()->with('success', 'Invoice paid successfully.');
    }

    public function getInvoiceView(Request $request, $id) {
        $uuid = null;
        $invoices = $this->recurlyService->getInvoicesForAccount($request->user()->user_identifier);
        foreach($invoices as $invoice) {
            if ($id == $invoice->invoice_number) {
                $uuid = $invoice->uuid; break;
            }
        }
        if (!$uuid) abort(404);
        $account = $this->recurlyService->getAccount($request->user());
        $link =  'https://ps.recurly.com/account/invoices/'.$id.'?ht='.$account->hosted_login_token;
        return view('billing.invoice', ['link'=>$link, 'number'=>$id]);
    }

    public function getInvoicePdf(Request $request, $id)
    {
        $user_identifier = Auth::user()->user_identifier;
        Recurly_Client::$subdomain  =     env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey     =     env('RECURLY_APIKEY');
        try {
            $invoices = $this->recurlyService->getInvoicesForAccount($user_identifier);
        } catch (\Recurly_NotFoundError $e) {
            return redirect()->back()->with('error', 'Error Occurred');
        }

        $match = FALSE;
        foreach($invoices as $invoice) {
            if ($id == $invoice->invoice_number) {
                $match = TRUE;
                break;
            }
        }
        if (!$match) return redirect()->back()->with('error', 'Error Occurred');

        try {
            $pdf = Recurly_Invoice::getInvoicePdf($id);
        } catch (\Recurly_NotFoundError $e) {
            return "Invoice not found: $e";
        }

        $file = storage_path().'/invoice.pdf';
        file_put_contents($file, $pdf);

        if (file_exists($file)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($file).'"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
    }

    
    public function getBillingInfo(Request $request) {
        $countries = \CountryState::getCountries();
        $billing_info = $this->recurlyService->getBillingInfo($request->user());
        $account = $this->recurlyService->getAccount($request->user());
        $states = \CountryState::getStates($billing_info ? $billing_info->country : 'US');

        $account_balance = $this->recurlyService->getAccountBalance($request->user(), FALSE);
        $past_due = $this->recurlyService->getPostDueAmount($request->user(), FALSE);
        $credit_value = $account_balance + $past_due;
        return view('billing.info', [
            'billing_info' => $billing_info,
            'countries' => $countries,
            'states' => $states,
            'account' => $account,
            'enable_vat' => $this->settings->get('enable_vat'),
            'billing_descriptor' => $this->settings->get('billing_descriptor'),
            'terms' => $this->settings->get('terms'),
            'last_four' => isset($billing_info->last_four) ? $billing_info->last_four : 'xxxx',
            'credit_value' => $credit_value,
        ]);
    }

    public function postBillingInfo(Request $request) {
        $this->recurlyService->payInvoicesByCredits($request->user());

        try {
            $account = $this->recurlyService->updateBillingInfo($request->all(), $request->user());
        } catch (\Recurly_NotFoundError $e) {
            return redirect()->action('BillingController@getBillingInfo')->with('error', $e->getMessage());
        } catch (Recurly_ValidationError $e) {
            return redirect()->action('BillingController@getBillingInfo')->with('error', $e->getMessage());
        }
        $this->recurlyService->cleanCache($request->user());
        return redirect()->action('BillingController@getBillingInfo')->with('success','Billing information has been updated');
    }

    /**
     * Return array of states by country code
     *
     * @return \Illuminate\Http\Response
     */
    public function getStates(Request $request) {
        if ($states = \CountryState::getStates(strtoupper($request->get('country')))) {
            $options = '';
            foreach($states as $code=>$country) {
                $options .= '<option value="'.$code.'">'.$country.'</option>';
            }
            $return = [
                'states' => $states,
                'options' => $options,
            ];
        } else {
            $return = [
                'states' => null
            ];
        }
        return response()->json($return);
    }

    public function cancelReasonValidator(array $data, $user) {
        $rules = [
            'uuid' => 'exists:customer_purchase_plans,uuid,customer_id,'.$user->id,
            'reason' => 'required|array',
        ];
        return Validator::make($data, $rules);
    }

    public function saveCancelReason(array $data, PurchasePlans $subscription, $user) {
        $reasons = $data['reason'];
        $cancel_reason = SubscriptionCancelReason::where('customer_id', $user->id)
          ->where('subscription_id', $subscription->id)
          ->first();
        if (!$cancel_reason) {
            $cancel_reason = SubscriptionCancelReason::create([
              'customer_id' => $user->id,
              'subscription_id' => $subscription->id,
              'cancel_reason' => json_encode($reasons),
              'cancel_comment' => $data['comment'],
            ]);
        } else {
            $cancel_reason->cancel_reason = json_encode($reasons);
            $cancel_reason->cancel_comment = $data['comment'];
            $cancel_reason->save();
        }
        $cancel_reason->ticket();
    }

    public function getUpdateCredit(Request $request) {
        $balance = $this->recurlyService->getAccountBalance($request->user(), FALSE);
        $past_due = $this->recurlyService->getPostDueAmount($request->user(), FALSE);
        $credit = $balance + $past_due;
        return json_encode(['credits'=>number_format($credit/100, 2), 'credit_in_cents'=>$credit, 'success'=>TRUE]);
    }

    public function getUpdatePastDue(Request $request) {
        $past_due = $this->recurlyService->getPostDueAmount($request->user(), FALSE);
        return json_encode(['past_due'=>number_format($past_due/100, 2), 'success'=>TRUE]);
    }

    public function postCheckCredit(Request $request) {
        $balance = FALSE;
        if ($request->has('amount')) {
            $amount = $request->get('amount');

            $balance = $this->recurlyService->getAccountBalance($request->user(), FALSE);
            $past_due = $this->recurlyService->getPostDueAmount($request->user(), FALSE);
            $credit = $balance + $past_due;

            if ($credit >= $amount) $balance = TRUE;
                else $balance = FALSE;
        }
        return ['balance' => $balance, 'invoice' => $request->get('invoice')];
    }
}
