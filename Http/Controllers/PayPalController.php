<?php

namespace App\Http\Controllers;

use App\Models\PurchasePlans;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Response;
use App\Services\RecurlyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class PayPalController extends Controller
{

    protected $recurlyService;
    protected $paymentService;

    function __construct(RecurlyService $recurlyService, PaymentService $paymentService) {
        $this->recurlyService = $recurlyService;
        $this->paymentService = $paymentService;
    }

    public function getPaypalPage(Request $request) {
        $past_due = $this->recurlyService->getPostDueAmount(Auth::user());
        $account_balance = $this->recurlyService->getAccountBalance($request->user());
        $credit_value = $account_balance + $past_due; $credit_value = $credit_value / 100;
        return view('billing.paypal', ['credit_value'=>$credit_value]);
    }

    public function postPaypalExpressCheckout(Request $request) {
        $validator = $this->validator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }
        $amount = $request->get('paypal_amount');

        $this->paymentService->setMethod('paypal');
        $this->paymentService->setItem('Proxystars Credits', $amount, 'USD', 'credit');
        $response = $this->paymentService->pay($amount, 'USD');
        if ($response['status'] && $response['redirect']) {
            return redirect()->away($response['redirect']);
        }
    }

    public function getPaypalSuccess(Request $request) {
        $user = $request->user();
        if ($request->has('paymentId') && $request->has('PayerID')) {
            $paymentId = $request->get('paymentId');
            $payerID = $request->get('PayerID');
            $response = $this->paymentService->paypalExecute($payerID, $paymentId);
            if ($response['status'] && $response['payment']) {
                $invoices = $this->recurlyService->getInvoicesForAccount($user->user_identifier);
                $amount = 0;
                foreach($response['payment']->transactions as $transaction) {
                    $amount = $amount + $transaction->amount->total;
                }
                $amount = $amount * 100; //amount in cents
                $description = 'PayPal Payment: '.$response['payment']->id;
                $this->recurlyService->createCredit($amount, $description, $user);

                /*
                 * Auto pay non-collected and non-failed invoices by paypal
                $account_balance = $this->recurlyService->getAccountBalance($user);
                if ($account_balance >= 0) {
                    foreach ($invoices as $invoice) {
                        if ($invoice->state != 'collected' && $invoice->state != 'failed') {
                            $description = 'Charge for invoice ID:' . $invoice->invoice_number;
                            $this->recurlyService->createCharge($invoice->total_in_cents, $description, $user);
                            $this->recurlyService->markInvoiceAsPaid($invoice->invoice_number);
                        }
                    }
                }
                */

                Cache::forget('post_due_'.$user->id);
                Cache::forget('account_balance_'.$user->id);
                return redirect()->action('PayPalController@getPaypalPage')->with('success', 'Transaction has been succeeded. Credits have been added to your account.');
            } else {
                return redirect()->action('PayPalController@getPaypalPage')->with('error', $response['message']);
            }
        }
        return redirect()->action('PayPalController@getPaypalPage')->with('error', 'Transaction error');
    }

    public function getPaypalCancel(Request $request) {
        return redirect()->action('PayPalController@getPaypalPage')->with('error', 'Transaction cancelled');
    }

    public function getPayment(Request $request) {
        if ($request->has('paymentId')) {
            $payment = $this->paymentService->getPaypalPayment($request->get('paymentId'));
            $amount = 0;
            foreach($payment->transactions as $transaction) {
                $amount = $amount + $transaction->amount->total;
            }
            p($payment);
            p($amount); exit;
        }
    }

    public function getSubscriptionTest(Request $request) {
        $created_plan = $this->paymentService->createPlan();
        $created_plan = $this->paymentService->updatePlan($created_plan);
        $url = $this->paymentService->createAgreement($created_plan);
        return redirect()->away($url);
    }

    public function getSubscriptionTestExecute(Request $request) {
        $token = $request->get('token');
        $agreement = $this->paymentService->executeAgreement($token);
        p($agreement); exit;
    }

    protected function validator($data) {
        return Validator::make($data, [
            'paypal_amount' => 'numeric|min:0',
        ]);
    }

}
