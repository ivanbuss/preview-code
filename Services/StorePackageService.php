<?php

namespace App\Services;

use App\Models\CustomerVPNData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\User;
use Carbon\Carbon;

class StorePackageService extends APIService {

    protected $proxyService;
    protected $vpnService;
    protected $routerService;

    public function __construct(StoreProxyService $proxyService, StoreVPNService $vpnService, StoreRouterService $routerService) {
        $this->proxyService = $proxyService;
        $this->vpnService = $vpnService;
        $this->routerService = $routerService;
    }

    public function createService(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $purchasePlans = $this->savePurchasePlan($plan, $user, $uuid, $ip, $expiration_date, $coupon_code);

        $bundleProducts = $plan->bundlePlans()->where('plan_type', 'vpn_dedicated')->get();
        $vpn_plan = $bundleProducts->first();
        $notvpnBundleProducts = $plan->bundlePlans()->where('plan_type', '!=', 'vpn_dedicated')->get();
        foreach($notvpnBundleProducts as $notvpnBundleProduct) {
            $bundleProducts->push($notvpnBundleProduct);
        }

        $is_router = FALSE;
        foreach ($bundleProducts as $bundleProduct) {
            if ($bundleProduct->plan_type == 'simple' || $bundleProduct->plan_type == 'complex' || $bundleProduct->plan_type == 'dedicated') {
                $response = $this->proxyService->createProxyService($bundleProduct, $user, $uuid, $ip, $expiration_date, $coupon_code);
            } else if ($bundleProduct->plan_type == 'vpn_dedicated') {
                $response = $this->vpnService->createVPNServer($bundleProduct, $user, $uuid, $ip, $expiration_date, $coupon_code);
            } else if ($bundleProduct->plan_type == 'router') {
                $response = $this->routerService->queueRouterService($bundleProduct, $user, $uuid, $ip, $expiration_date, $coupon_code);
                $is_router = TRUE;
            }
        }

        if ($is_router) $response['is_router'] = TRUE;
            else $response['is_router'] = FALSE;

        return $response;
    }

    public function savePurchasePlan(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $status = PurchasePlans::ACTIVE; $client_ip = null;
        $local_status = 'enabled';

        if (!$expiration_date) {
            $duration_months = $plan->getDurationInMonths();
            if ($duration_months) {
                $expiration_date = Carbon::now()->addMonths($duration_months);
            } else {
                $duration_days = $plan->getDurationInMonths();
                if ($duration_days) $expiration_date = Carbon::now()->addDays($duration_days);
            }
        }

        if ($ip) {
            if (is_array($ip) && isset($ip[0])) $client_ip = $ip[0];
            else $client_ip = $ip;
        }

        $purchasePlans  = PurchasePlans::create([
          'uuid'			=>  $uuid,
          'customer_id'     =>  $user->id,
          'plan_id'         =>  $plan->id,
          'category_id'     =>  $plan->category_id,
          'ip_address'      =>  $client_ip,
          'coupon_code'     =>  $coupon_code,
          'purchase_date'   =>  Carbon::now(),
          'expiration_date' =>  $expiration_date,
          'status'          =>  $status,
          'local_status'    =>  $local_status,
        ]);

        return $purchasePlans;
    }

    public function enableService(PurchasePlans $purchase_plan, User $user) {
        $this->vpnService->enableVPNServer($purchase_plan, $user);
        $this->routerService->enableRouter($purchase_plan, $user);
        $this->proxyService->enableProxy($purchase_plan, $user);
    }

    public function disableService(PurchasePlans $purchase_plan, User $user) {
        $this->vpnService->disableVPNServer($purchase_plan, $user);
        $this->routerService->disableRouter($purchase_plan, $user);
        $this->proxyService->disableProxy($purchase_plan, $user);
    }

    public function deleteService(PurchasePlans $purchase_plan, User $user) {
        $this->vpnService->deleteVPNData($purchase_plan, $user, FALSE);
        $this->routerService->disableRouter($purchase_plan, $user);
        $this->proxyService->deleteProxyData($purchase_plan, $user, FALSE);
    }

}
