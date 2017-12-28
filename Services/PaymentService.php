<?php

namespace App\Services;

use App\Models\PsPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use PayPal\Api\Agreement;
use PayPal\Api\Amount;
use PayPal\Api\ChargeModel;
use PayPal\Api\Currency;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\MerchantPreferences;
use PayPal\Api\Patch;
use PayPal\Api\PatchRequest;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentDefinition;
use PayPal\Api\Plan;
use PayPal\Api\RedirectUrls;
use PayPal\Api\ShippingAddress;
use PayPal\Api\Transaction;
use PayPal\Common\PayPalModel;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\PaymentExecution;

class PaymentService {

    private $_apiContext;


    protected $method;
    protected $items;

    public function __construct() {
        $this->_apiContext = new ApiContext(
            new OAuthTokenCredential(
                config('paypal.client_id', null),
                config('paypal.client_secret', null)
            )
        );
        // dynamic configuration instead of using sdk_config.ini
        $this->_apiContext->setConfig(array(
            'mode' => config('paypal.mode', 'sandbox'),
            'http.ConnectionTimeOut' => 30,
            'log.LogEnabled' => true,
            'log.FileName' => __DIR__ . '/../../storage/logs/PayPal.log',
            'log.LogLevel' => 'FINE'
        ));
    }

    public function setMethod($method) {
        $this->method = $method;
    }

    public function setItem($name, $price, $currency, $number) {
        $item1 = new Item();
        $item1->setName($name)
            ->setCurrency($currency)
            ->setQuantity(1)
            ->setSku($number)
            ->setPrice($price);
        $itemList = new ItemList();
        $itemList->setItems(array($item1));
        $this->items = $itemList;
    }

    public function pay($total, $currency) {
        $payer = new Payer;
        $payer->setPaymentMethod($this->method);

        $amount = new Amount();
        $amount->setCurrency($currency)
            ->setTotal($total);

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($this->items)
            ->setDescription("Payment description")
            ->setInvoiceNumber(uniqid());

        $payment = new Payment();
        $payment->setIntent("sale")
            ->setPayer($payer)
            ->setTransactions(array($transaction));

        if ($this->method == 'paypal') {
            $redirectUrls = $this->setRedirects();
            $payment->setRedirectUrls($redirectUrls);
        }

        try {
            $payment->create($this->_apiContext);
            $this->createPayment($payment, $this->method);
        } catch (Exception $ex) {
            return array('status'=>0, 'message'=>$ex->getMessage(), 'redirect'=>'');
        }

        $approvalUrl = '';
        if ($this->method == 'paypal') {
            $approvalUrl = $payment->getApprovalLink();
            if (config('paypal.mode') == 'sandbox' && substr_count($approvalUrl, 'sandbox.paypal') === 0) {
                $approvalUrl = str_replace('paypal', 'sandbox.paypal', $approvalUrl);
            }
        }

        return array('status'=>1, 'message'=>'', 'redirect'=>$approvalUrl);
    }

    private function setRedirects() {
        $success_url = action('PayPalController@getPaypalSuccess');
        $cancel_url = action('PayPalController@getPaypalCancel');

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($success_url)
            ->setCancelUrl($cancel_url);
        return $redirectUrls;
    }

    public function paypalExecute($payer_id, $payment_id) {
        $payment = Payment::get($payment_id, $this->_apiContext);

        $execution = new PaymentExecution();
        $execution->setPayerId($payer_id);

        try {
            $result = $payment->execute($execution, $this->_apiContext);
            $payment = $this->getPaypalPayment($payment_id);
            if (!$payment) return array('status'=>0, 'message'=>'', 'payment'=>'');
        } catch (Exception $ex) {
            return array('status'=>0, 'message'=>$ex->getMessage(), 'payment'=>'');
        } catch (PayPalConnectionException $ex) {
            return array('status'=>0, 'message'=>$ex->getMessage(), 'payment'=>'');
        }
        $this->approvePayment($payment);
        return array('status'=>1, 'payment'=>$payment, 'message'=>'');
    }

    public function getPaypalPayment($payment_id) {
        try {
            $payment = Payment::get($payment_id, $this->_apiContext);
            $this->approvePayment($payment);
            return $payment;
        } catch (Exception $ex) {
            return FALSE;
        }
    }

    public function createPlan() {
        $plan = new Plan();

        $plan->setName('Proxystars subscription')
            ->setDescription('Service subscription.')
            ->setType('INFINITE');

        $paymentDefinition = new PaymentDefinition();

        $paymentDefinition->setName('Regular Payments')
            ->setType('REGULAR')
            ->setFrequency('MONTH')
            ->setFrequencyInterval("1")
            //->setCycles("12")
            ->setAmount(new Currency(array('value' => 15, 'currency' => 'USD')));

        /*
        $chargeModel = new ChargeModel();
        $chargeModel->setType('SHIPPING')
            ->setAmount(new Currency(array('value' => 10, 'currency' => 'USD')));
        $paymentDefinition->setChargeModels(array($chargeModel));
        */

        $merchantPreferences = new MerchantPreferences();

        $success_url = action('PayPalController@getSubscriptionTestExecute');
        $merchantPreferences->setReturnUrl($success_url)
            ->setCancelUrl("http://proxystars/ExecuteAgreement.php?success=false")
            ->setAutoBillAmount("yes")
            ->setInitialFailAmountAction("CONTINUE")
            ->setMaxFailAttempts("0")
            ->setSetupFee(new Currency(array('value' => 15, 'currency' => 'USD')));


        $plan->setPaymentDefinitions(array($paymentDefinition));
        $plan->setMerchantPreferences($merchantPreferences);

        try {
            $output = $plan->create($this->_apiContext);
        } catch (Exception $ex) {
            p($ex->getMessage()); exit;
        }
        return $output;
    }

    public function updatePlan($createdPlan) {
        try {
            $patch = new Patch();

            $value = new PayPalModel('{
	            "state":"ACTIVE"
	        }');

            $patch->setOp('replace')
                ->setPath('/')
                ->setValue($value);
            $patchRequest = new PatchRequest();
            $patchRequest->addPatch($patch);

            $createdPlan->update($patchRequest, $this->_apiContext);

            $plan = Plan::get($createdPlan->getId(), $this->_apiContext);
        } catch (Exception $ex) {
            p($ex->getMessage()); exit;
        }
        return $plan;
    }

    public function createAgreement($createdPlan) {
        $agreement = new Agreement();
        $agreement->setName('Some Base Agreement')
            ->setDescription('Some Basic Agreement text')
            ->setStartDate(Carbon::now()->addMinute()->format('Y-m-d\TG:i:s\Z'));
            //->setStartDate('2019-06-17T9:45:04Z');

        $plan = new Plan();
        $plan->setId($createdPlan->getId());
        $agreement->setPlan($plan);

        $payer = new Payer();
        $payer->setPaymentMethod('paypal');
        $agreement->setPayer($payer);

        /*
        $shippingAddress = new ShippingAddress();
        $shippingAddress->setLine1('111 First Street')
            ->setCity('Saratoga')
            ->setState('CA')
            ->setPostalCode('95070')
            ->setCountryCode('US');
        $agreement->setShippingAddress($shippingAddress);
        */

        try {
            // Please note that as the agreement has not yet activated, we wont be receiving the ID just yet.
            $agreement = $agreement->create($this->_apiContext);
            $approvalUrl = $agreement->getApprovalLink();
        } catch (Exception $ex) {
            p($ex->getMessage()); exit;
            exit;
        }

        return $approvalUrl;
    }

    public function executeAgreement($token) {
        $agreement = new \PayPal\Api\Agreement();
        try {
            $agreement->execute($token, $this->_apiContext);
        } catch (Exception $ex) {
            p($ex->getMessage()); exit;
        }

        try {
            $agreement = \PayPal\Api\Agreement::get($agreement->getId(), $this->_apiContext);
        } catch (Exception $ex) {
            p($ex->getMessage()); exit;
        }

        return $agreement;
    }

    protected function createPayment($payment, $method) {
        $amount = 0;
        foreach($payment->transactions as $transaction) {
            $amount = $amount + $transaction->amount->total;
            $currency = $transaction->amount->currency;
        }
        return PsPayment::create([
            'user_id' => Auth::user()->id,
            'method' => $method,
            'payment_id' => $payment->id,
            'state' => $payment->state,
            'amount' => $amount,
            'currency' => $currency,
            'create_time' => Carbon::createFromFormat(\DateTime::ISO8601, $payment->create_time),
        ]);
    }

    protected function approvePayment($payment) {
        $method = $payment->payer->payment_method;
        $ps_payment = PsPayment::where('user_id', Auth::user()->id)
            ->where('method', $method)
            ->where('payment_id', $payment->id)
            ->first();
        if (!$ps_payment) return FALSE;

        $ps_payment->state = $payment->state;
        if ($payment->update_time) {
            $ps_payment->update_time = Carbon::createFromFormat(\DateTime::ISO8601, $payment->update_time);
        }
        $ps_payment->save();

        return TRUE;
    }

    public function test() {
        $payer = new Payer();
        $payer->setPaymentMethod("paypal");

        $item1 = new Item();
        $item1->setName('Ground Coffee 40 oz')
            ->setCurrency('USD')
            ->setQuantity(1)
            ->setSku("123123") // Similar to `item_number` in Classic API
            ->setPrice(7.5);
        $item2 = new Item();
        $item2->setName('Granola bars')
            ->setCurrency('USD')
            ->setQuantity(5)
            ->setSku("321321") // Similar to `item_number` in Classic API
            ->setPrice(2);

        $itemList = new ItemList();
        $itemList->setItems(array($item1, $item2));

        $details = new Details();
        $details->setShipping(1.2)
            ->setTax(1.3)
            ->setSubtotal(17.50);

        $amount = new Amount();
        $amount->setCurrency("USD")
            ->setTotal(20)
            ->setDetails($details);

        $transaction = new Transaction();
        $transaction->setAmount($amount)
            ->setItemList($itemList)
            ->setDescription("Payment description")
            ->setInvoiceNumber(uniqid());

        $success_url = action('PayPalController@getSubscriptionTestExecute');
        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($success_url)
            ->setCancelUrl($success_url);

        $payment = new Payment();
        $payment->setIntent("sale")
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions(array($transaction));

        $request = clone $payment;

        try {
            $payment->create($this->_apiContext);
        } catch (Exception $ex) {
            // NOTE: PLEASE DO NOT USE RESULTPRINTER CLASS IN YOUR ORIGINAL CODE. FOR SAMPLE ONLY
            ResultPrinter::printError("Created Payment Using PayPal. Please visit the URL to Approve.", "Payment", null, $request, $ex);
            exit(1);
        }
        $approvalUrl = $payment->getApprovalLink();
        return $approvalUrl;
    }
}
