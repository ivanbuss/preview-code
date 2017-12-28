<?php

namespace App\Http\Controllers;

use App\Models\CustomerProxyData;
use App\Models\RecurlyLog;
use App\Services\RecurlyService;
use App\Services\StorePackageService;
use App\Services\StoreProxyService;
use App\Services\StoreRouterService;
use App\Services\StoreVPNService;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Requests;

use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Mockery\CountValidator\Exception;
use Recurly_Client;
use Recurly_Subscription;
use Recurly_NotFoundError;

class WebHooksController extends Controller
{

    protected $proxyService;
    protected $vpnService;
    protected $routerService;
    protected $packageService;
    protected $recurlyService;

    public function __construct(StoreProxyService $proxyService, StoreVPNService $VPNService, StoreRouterService $routerService, StorePackageService $packageService, RecurlyService $recurlyService) {
        $this->proxyService = $proxyService;
        $this->vpnService = $VPNService;
        $this->routerService = $routerService;
        $this->packageService = $packageService;
        $this->recurlyService = $recurlyService;
    }

    /**
     * @param Request $request
     * @return mixed
     * @throws Exception
     */
    public function recurlyWebhooks(Request $request){
        Recurly_Client::$subdomain =  env('RECURLY_SUBDOMAIN');
        Recurly_Client::$apiKey    =  env('RECURLY_APIKEY');

        $post_xml = file_get_contents ("php://input");

        try {
            $notification = new \App\Helper\Recurly_PushNotification($post_xml);
        }catch (Recurly_NotFoundError $e) {
            //print "Subscription Not Found: $e";
        }
        $this->saveLog($notification->type, $notification);

        switch ($notification->type){
            case 'updated_subscription_notification':
                return $this->subscriptionUpdateNotification($notification); break;
            case 'failed_payment_notification':
                return $this->failedPaymentNotification($notification); break;
            case 'successful_payment_notification':
                return $this->successfulPaymentNotification($notification); break;
            case 'expired_subscription_notification':
                return $this->expiredSubscriptionNotification($notification); break;
            case 'successful_refund_notification':
                return $this->successfulRefundNotification($notification); break;
            case 'void_payment_notification':
                return $this->voidPaymentNotification($notification); break;
            case 'renewed_subscription_notification':
                return $this->renewedSubscriptionNotification($notification); break;
                //return $this->subscriptionUpdateNotification($notification); break;
            case 'canceled_subscription_notification':
                return $this->canceledSubscriptionNotification($notification); break;
            case 'reactivated_account_notification':
                return $this->reactivatedAccountNotification($notification); break;
            /*
            case 'new_subscription_notification':
                return $this->createNewSubscription($notification); break;
            */
            case 'closed_invoice_notification':
                return $this->closedInvoiceNotification($notification); break;
            default:
                break;
        }

    }

    protected function closedInvoiceNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->invoice->subscription_id;
            $invoice_state = (string)$notification->invoice->state;
            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->first();
            if ($invoice_state == 'collected' && $purchase_plan && $purchase_plan->status == 'active' && $purchase_plan->local_status == 'disabled') {
                $plan = $purchase_plan->plan;
                $accountObj = User::where('user_identifier', $account_code)->first();
                $this->recurlyService->cleanCache($accountObj);
                if ($plan && $plan->plan_type == 'package') {
                    $this->packageService->enableService($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->enableVPNServer($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'router') {
                    $this->routerService->enableRouter($purchase_plan, $accountObj);
                } else {
                    $this->proxyService->enableProxy($purchase_plan, $accountObj);
                }
                $purchase_plan->local_status = 'enabled';
                $purchase_plan->save();
            }
        }
    }

    /**
     * Not used
     * @param $notification
     */
    protected function createNewSubscription($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $uuid = (string)  $notification->subscription->uuid;
            $expiration_date = (string) $notification->subscription->current_period_ends_at;
            $expiration_date = Carbon::createFromFormat(\DateTime::ISO8601, $expiration_date);

            $accountObj = User::where('user_identifier',$account_code)->first();
            $purchase_plan = PurchasePlans::where('uuid', $uuid)->where('customer_id', $accountObj->id)->first();
            if (!$purchase_plan) {
                $plan_code = (string)  $notification->subscription->plan->plan_code;
                $plan = RecurlyProducts::where('plan_code', $plan_code)->where('billing_type', 'duration')->first();
                if ($plan) {
                    try {
                        if ($plan->plan_type == 'package') {
                            $response = $this->packageService->createService($plan, $accountObj, $uuid, null, $expiration_date);
                        } else if ($plan->plan_type == 'vpn_dedicated') {
                            $response = $this->vpnService->createVPNServer($plan, $accountObj, $uuid, NULL, $expiration_date);
                        } else if ($plan->plan_type == 'router') {
                            $response = $this->routerService->createRouterService($plan, null, $accountObj, $uuid, NULL, NULL, $expiration_date);
                        } else {
                            $response = $this->proxyService->createProxyService($plan, $accountObj, $uuid, null, $expiration_date);
                        }
                        if ($response['success'] != FALSE) {
                            Log::error('Error with creating manual subscription with UUID='.$uuid.' for Account Code='.$account_code);
                        }
                    } catch(\Exception $e) {
                        Log::error('Error with exception with creating manual subscription with UUID='.$uuid.' for Account Code='.$account_code.' with message '.$e->getMessage());
                    }
                } else {
                    Log::error('Error with creating manual subscription with UUID='.$uuid.': plan with code:'.$plan_code.' has not been found');
                }
            }
        }
    }

    protected function subscriptionUpdateNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $uuid = (string)  $notification->subscription->uuid;
            $expiration_date = (string) $notification->subscription->current_period_ends_at;
            $purchase_plan = PurchasePlans::where('uuid', $uuid)->firstOrFail();
            if ($purchase_plan) {
                $purchase_plan->expiration_date = Carbon::createFromFormat(\DateTime::ISO8601, $expiration_date);
                $purchase_plan->status = (string) $notification->subscription->state;
                $purchase_plan->save();
            }
        }
    }

    protected function successfulPaymentNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->transaction->subscription_id;

            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->first();
            if ($purchase_plan && $purchase_plan->status == PurchasePlans::ACTIVE && $purchase_plan->local_status == 'disabled') {
                $plan = $purchase_plan->plan;
                $accountObj = User::where('user_identifier', $account_code)->first();
                $this->recurlyService->cleanCache($accountObj);
                if ($plan && $plan->plan_type == 'package') {
                    $this->packageService->enableService($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->enableVPNServer($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'router') {
                    $this->routerService->enableRouter($purchase_plan, $accountObj);
                } else {
                    $this->proxyService->enableProxy($purchase_plan, $accountObj);
                }
                $purchase_plan->local_status = 'enabled';
                $purchase_plan->save();
            }
        }
    }

    protected function failedPaymentNotification($notification) {
        if ($notification) {
            Cache::flush();
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->transaction->subscription_id;

            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->first();
            if ($purchase_plan && $purchase_plan->status == PurchasePlans::ACTIVE && $purchase_plan->local_status == 'enabled') {
                $plan = $purchase_plan->plan;
                $accountObj = User::where('user_identifier', $account_code)->first();
                $this->recurlyService->cleanCache($accountObj);
                if ($plan && $plan->plan_type == 'package') {
                    $this->packageService->disableService($purchase_plan, $accountObj);
                } if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->disableVPNServer($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'router') {
                    $this->routerService->disableRouter($purchase_plan, $accountObj);
                } else {
                    $this->proxyService->disableProxy($purchase_plan, $accountObj);
                }
                $purchase_plan->local_status = 'disabled';
                $purchase_plan->save();
            }
        }
    }

    protected function expiredSubscriptionNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->subscription->uuid;

            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->with('proxy_data')->first();
            if ($purchase_plan) {
                $plan = $purchase_plan->plan;
                $accountObj = User::where('user_identifier',$account_code)->first();
                if ($purchase_plan && $purchase_plan->local_status != 'deleted' && $purchase_plan->local_status != PurchasePlans::PROVISIONING && $purchase_plan->status != PurchasePlans::PROVISIONING) {
                    if ($plan && $plan->plan_type == 'package') {
                        $this->packageService->deleteService($purchase_plan, $accountObj);
                    } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                        $this->vpnService->deleteVPNData($purchase_plan, $accountObj, FALSE);
                    } else if ($plan && $plan->plan_type == 'router') {
                        $this->routerService->disableRouter($purchase_plan, $accountObj);
                    } else {
                        $this->proxyService->deleteProxyData($purchase_plan, $accountObj, FALSE);
                    }
                }
                $purchase_plan->status = (string)  $notification->subscription->state;
                $purchase_plan->local_status = 'deleted';
                $purchase_plan->save();
            }
        }
    }

    protected function successfulRefundNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->transaction->subscription_id;
            /*
            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->with('proxy_data')->first();
            if ($purchase_plan) {
                $plan = $purchase_plan->plan;
                $accountObj = User::where('user_identifier',$account_code)->first();
                if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->disableVPNServer($purchase_plan, $accountObj);
                } else {
                    $this->proxyService->disableProxy($purchase_plan, $accountObj);
                }
            }
            */
        }
    }

    protected function voidPaymentNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->transaction->subscription_id;

            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->with('proxy_data')->first();
            if ($purchase_plan && $purchase_plan->local_status != 'disabled' && $purchase_plan->local_status != PurchasePlans::PROVISIONING && $purchase_plan->status != PurchasePlans::PROVISIONING) {
                $plan = $purchase_plan->plan;
                $accountObj = User::where('user_identifier',$account_code)->first();
                if ($plan && $plan->plan_type == 'package') {
                    $this->packageService->disableService($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                    $this->vpnService->disableVPNServer($purchase_plan, $accountObj);
                } else if ($plan && $plan->plan_type == 'router') {
                    $this->routerService->disableRouter($purchase_plan, $accountObj);
                } else {
                    $this->proxyService->disableProxy($purchase_plan, $accountObj);
                }
                $purchase_plan->local_status = 'disabled';
                $purchase_plan->save();
            }
        }
    }

    protected function renewedSubscriptionNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $uuid = (string)  $notification->subscription->uuid;
            $expiration_date = (string) $notification->subscription->current_period_ends_at;
            $purchase_plan = PurchasePlans::where('uuid', $uuid)->firstOrFail();
            if ($purchase_plan) {
                $purchase_plan->expiration_date = Carbon::createFromFormat(\DateTime::ISO8601, $expiration_date);
                $purchase_plan->status = (string) $notification->subscription->state;

                $subscription = $this->recurlyService->getSubscription($uuid);
                $invoice_obj = $subscription->invoice;
                if ($invoice_obj) {
                    $invoice = $invoice_obj->get();
                    $state = $invoice->state;
                    if ($state != 'collected') {
                        $plan = $purchase_plan->plan;
                        $accountObj = User::where('user_identifier', $account_code)->first();
                        $this->recurlyService->cleanCache($accountObj);
                        if ($purchase_plan->status == PurchasePlans::ACTIVE && $purchase_plan->local_status == 'enabled') {
                            if ($plan && $plan->plan_type == 'packages') {
                                $this->packageService->disableService($purchase_plan, $accountObj);
                            } else if ($plan && $plan->plan_type == 'vpn_dedicated') {
                                $this->vpnService->disableVPNServer($purchase_plan, $accountObj);
                            } else if ($plan && $plan->plan_type == 'router') {
                                $this->routerService->disableRouter($purchase_plan, $accountObj);
                            } else {
                                $this->proxyService->disableProxy($purchase_plan, $accountObj);
                            }
                            $purchase_plan->local_status = 'disabled';
                        }
                    }
                }
                $purchase_plan->save();
            }
        }
    }

    protected function canceledSubscriptionNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->subscription->uuid;

            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->first();
            if ($purchase_plan) {
                $purchase_plan->status = (string) $notification->subscription->state;
                $purchase_plan->save();
            }
        }
    }

    protected function reactivatedAccountNotification($notification) {
        if ($notification) {
            $account_code = (string) $notification->account->account_code;
            $subscription_id = (string)  $notification->subscription->uuid;

            $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->first();
            if ($purchase_plan) {
                $purchase_plan->status = (string) $notification->subscription->state;
                $purchase_plan->save();
            }
        }
    }

    /**
     * To enable or disable the plan in customer proxy data table
     * @param $subscription_id
     * @param $status
     */
    protected function enableDisableProxy($subscription_id, $status)
    {
        CustomerProxyData::where('uuid',$subscription_id)->update(['enabled'=> $status]);
    }

    /**
     * To enable or disable the plan in customer proxy data table
     * @param $subscription_id
     * @param $status
     */
    protected function expireProxy($subscription_id, $status)
    {
        CustomerProxyData::where('uuid',$subscription_id)->update(['expired'=> $status]);
    }

    /**
     * To send request to store proxy to update the subscription
     * @param $subscription_id
     * @param $account_code
     * @param $request_type
     * @return mixed
     */
    protected function storeProxyRequest($subscription_id, $account_code, $request_type)
    {
        $purchase_plan = PurchasePlans::where('uuid', $subscription_id)->with('proxy_data')->first();
        $accountObj = User::where('user_identifier',$account_code)->first();

        foreach($purchase_plan->proxy_data as $item)
        {
            $data = array(
                "store_proxy_id" => $purchase_plan->id . '-' . $item->id,
                "store_account_id" => $accountObj->user_identifier,
            );
            //encoding to json format
            $jsondata = json_encode($data);
            $url = env($request_type);
            try {
                $data = curlWrap("storeproxy", $url, $jsondata, "POST");
            } catch (Exception $e) {
                return [ 'error' => 'Store proxy is not responding! Please try later Thanks' ];
            }
        }
    }

    protected function saveLog($type, $notification) {
        $log = RecurlyLog::create([
            'type' => $type,
            'account_code' => isset($notification->account) ? (string)$notification->account->account_code : null,
            'subscription_id' => isset($notification->subscription) ? (string)$notification->subscription->uuid : null,
            'transaction_id' => isset($notification->transaction) ? (string)$notification->transaction->id : null,
        ]);
    }
}
