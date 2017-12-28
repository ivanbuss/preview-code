<?php

namespace App\Services;

use App\Models\CustomerRouterData;
use App\Models\CustomerVPNData;
use App\Models\CustomerVPNServerUsers;
use App\Models\CustomerVPNUsers;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\User;
use Carbon\Carbon;

class StoreRouterService extends APIService{

    public function __construct() {

    }


    public function savePurchasePlan(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $status = PurchasePlans::ACTIVE; $client_ip = '';
        $local_status = 'enabled';

        if ($plan->plan_type == 'router') {
            $count = $this->getAvailableRouters($plan, $user);
            if (!$count || $count < 1) {
                $status = PurchasePlans::PROVISIONING;
                $local_status = PurchasePlans::PROVISIONING;
            }
        }

        if (!$expiration_date) {
            if ($plan->isUnlimited()) {
                $expiration_date = null;
            } else {
                $duration_months = $plan->getDurationInMonths();
                if ($duration_months) {
                    $expiration_date = Carbon::now()->addMonths($duration_months);
                } else {
                    $duration_days = $plan->getDurationInDays();
                    if ($duration_days) {
                        $expiration_date = Carbon::now()->addDays($duration_days);
                    } else {
                        $expiration_date = Carbon::now()->addSeconds($plan->getDurationSeconds());
                    }
                }
            }
        }

        if ($ip) {
            if (is_array($ip) && isset($ip[0])) $client_ip = $ip[0];
                else $client_ip = $ip;
        }

        $purchasePlans = PurchasePlans::where('uuid', $uuid)->where('customer_id', $user->id)->first();
        if (!$purchasePlans) {
            $purchasePlans = PurchasePlans::create([
                'uuid' => $uuid,
                'customer_id' => $user->id,
                'plan_id' => $plan->id,
                'category_id' => $plan->category_id,
                'ip_address' => $client_ip,
                'coupon_code' => $coupon_code,
                'purchase_date' => Carbon::now(),
                'expiration_date' => $expiration_date,
                'status' => $status,
                'local_status' => $local_status,
            ]);
        } else {
            $purchasePlans->expiration_date = $expiration_date;
            if ($purchasePlans->status != PurchasePlans::PROVISIONING && $purchasePlans->local_status != PurchasePlans::PROVISIONING) {
                $purchasePlans->status = $status;
                $purchasePlans->local_status = $local_status;
            }
            $purchasePlans->save();
        }
        return $purchasePlans;
    }


    public function reactivateRouterService(PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        $router_data = CustomerRouterData::where('customer_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('uuid', $purchasePlans->uuid)
            ->first();
        if ($router_data) {
            $this->enableRouter($purchasePlans, $user);
            $router_data->enabled = 1;
            $router_data->expired = 0;
            $router_data->save();
        }
        return TRUE;
    }

    public function queueRouterService(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $purchasePlans = $this->savePurchasePlan($plan, $user, $uuid, $ip, $expiration_date, $coupon_code);
        if ($purchasePlans->status == PurchasePlans::PROVISIONING || $purchasePlans->local_status == PurchasePlans::PROVISIONING) return ['success'=>TRUE, 'purchase_plan'=>$purchasePlans];

        $store_router_id = $purchasePlans->id.'-'.$plan->id;
        if (env('APP_ENV') == 'local') $store_router_id .= '-local';

        $location_id = null;
        $vpnData = CustomerVPNData::where('uuid', $uuid)->first();
        if ($vpnData && $vpn_plan = $vpnData->plan) {
            if ($vpn_plan->isSimpleVPN()) {
                $vpn_plan = null;
            }
        }
        if (!$vpnData) $location_id = env('DEFAULT_ROUTER_LOCATION');
        $customerRouterData = $this->saveQueuedRouter($plan, $store_router_id, $vpnData, $location_id, $user, $uuid);
        return ['success'=>TRUE, 'purchase_plan'=>$purchasePlans, 'routerdata'=>$customerRouterData];
    }

    public function createRouterService(RecurlyProducts $plan, User $user, $uuid, $macaddess, $vpn_server = null, $ip = null, $expiration_date = null, $coupon_code = null) {
        $purchasePlans = $this->savePurchasePlan($plan, $user, $uuid, $ip, $expiration_date, $coupon_code);
        if ($purchasePlans->status == PurchasePlans::PROVISIONING || $purchasePlans->local_status == PurchasePlans::PROVISIONING) return ['success'=>TRUE, 'purchase_plan'=>$purchasePlans];
        $store_router_id = $purchasePlans->id.'-'.$plan->id;
        if (env('APP_ENV') == 'local') $store_router_id .= '-local';

        $data = [
          'port' => 1194,
          'lan_ip' => '10.3.2.1',
          'lan_netmask' => '255.255.255.0',
          'dns_server1' => '208.67.222.222',
          'dns_server2' => '208.67.220.220',
          'wifi_ssid' => 'VPNSTARS',
          'wifi_password' => bin2hex(openssl_random_pseudo_bytes(4)),
        ];

        $location_id = null;
        $vpn_server = CustomerVPNData::where('uuid', $uuid)->first();
        if ($vpn_server && $vpn_plan = $vpn_server->plan) {
            if ($vpn_plan->isSimpleVPN()) {
                $vpn_plan = null;
            }
        }

        $vpn_server_id = $vpn_server ? $vpn_server->server_id : null;
        $location_id = env('DEFAULT_ROUTER_LOCATION');
        $response = $this->provisionRouter($store_router_id, $vpn_server_id, $location_id, $macaddess, $plan, $user, $data);

        if ((!$response['success'] || $response['success'] == FALSE)
          && (!isset($response['macaddress']) || !$response['macaddress'])
          && (!isset($response['activation_code']) || !$response['activation_code'])) {
            $purchasePlans->local_status = PurchasePlans::ERROR;
            $purchasePlans->save();
            $response['purchase_plan'] = $purchasePlans;
            return $response;
        }

        $activation_code = $response['activation_code'];
        $macaddress = $response['macaddress'];
        $customerRouterData = $this->saveRouterData($plan, $macaddress, $activation_code, $store_router_id, $vpn_server, $location_id, $user, $uuid, $data);
        $response['routerdata'] = $customerRouterData;

        $purchasePlans->local_status = 'enabled';
        $purchasePlans->save();
        $response['purchase_plan'] = $purchasePlans;
        return $response;
    }

    public function createPurchaseRouterService(PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        $count = $this->getAvailableRouters($plan, $user);
        if ($count || $count > 0) {
            $store_router_id = $purchasePlans->id.'-'.$plan->id;
            if (env('APP_ENV') == 'local') $store_router_id .= '-local';

            $data = [
                'port' => 1194,
                'lan_ip' => '10.3.2.1',
                'lan_netmask' => '255.255.255.0',
                'dns_server1' => '208.67.222.222',
                'dns_server2' => '208.67.220.220',
                'wifi_ssid' => 'VPNSTARS',
                'wifi_password' => bin2hex(openssl_random_pseudo_bytes(4)),
            ];

            $location_id = null;
            $vpn_server = CustomerVPNData::where('uuid', $purchasePlans->uuid)->first();
            if ($vpn_server && $vpn_plan = $vpn_server->plan) {
                if ($vpn_plan->isSimpleVPN()) {
                    $vpn_plan = null;
                }
            }

            $vpn_server_id = $vpn_server ? $vpn_server->server_id : null;
            $location_id = env('DEFAULT_ROUTER_LOCATION');
            $response = $this->provisionRouter($store_router_id, $vpn_server_id, $location_id, $plan, $user, $data);
            if ((!$response['success'] || $response['success'] == FALSE)
                && (!isset($response['macaddress']) || !$response['macaddress'])
                && (!isset($response['activation_code']) || !$response['activation_code'])) {
                $purchasePlans->local_status = PurchasePlans::ERROR;
                $purchasePlans->save();
                $response['purchase_plan'] = $purchasePlans;
                return $response;
            }

            $activation_code = $response['activation_code'];
            $macaddress = $response['macaddress'];
            $customerRouterData = $this->saveRouterData($plan, $macaddress, $activation_code, $store_router_id, $vpn_server, $user, $purchasePlans->uuid, $data);
            $response['routerdata'] = $customerRouterData;

            $purchasePlans->status = PurchasePlans::ACTIVE;
            $purchasePlans->local_status = 'enabled';
            $purchasePlans->save();
            $response['purchase_plan'] = $purchasePlans;
            return $response;
        }
    }

    public function getAvailableRouters(RecurlyProducts $plan, User $user) {
        $data = array(
            'model' => $plan->router_model,
        );
        $response = $this->apiCall($data, 'STOREVPN_ROUTER_COUNT', 'GET');
        if (isset($response['count']) && !empty($response['count'])) return $response['count'];
            else return FALSE;
    }

    public function provisionRouter($store_router_id, $vpn_server_id = null, $entry_location_id = null, $macaddress, RecurlyProducts $plan, User $user, $data = []) {
        if (!$vpn_server_id && !$entry_location_id) return FALSE;
        $data = [
            'vpn_service_id' => $user->user_identifier,
            'store_router_id' => $store_router_id,
            'model' => $plan->router_model,
            'macaddress' => $macaddress,
            'has_maintenance' => TRUE,
            'port' => isset($data['port']) ? $data['port'] : 1194,
            'lan_ip' => isset($data['lan_ip']) ? $data['lan_ip'] : '10.3.2.1',
            'lan_netmask' => isset($data['lan_netmask']) ? $data['lan_netmask'] : '255.255.255.0',
            'dns_server1' => isset($data['dns_server1']) ? $data['dns_server1'] : '208.67.222.222',
            'dns_server2' => isset($data['dns_server2']) ? $data['dns_server2'] : '208.67.220.220',
            'wifi_ssid' => isset($data['wifi_ssid']) ? $data['wifi_ssid'] : 'VPNSTARS',
            'wifi_password' => isset($data['wifi_password']) ? $data['wifi_password'] : bin2hex(openssl_random_pseudo_bytes(4)),
            'store_account_id' => $user->user_identifier,
        ];
        if ($vpn_server_id) $data['entry_server_id'] = $vpn_server_id;
            else if ($entry_location_id) $data['entry_location_id'] = $entry_location_id;
        $response = $this->apiCall($data, 'STOREVPN_ROUTER_PROVISION');
        return $response;
    }

    public function registerRouter(CustomerRouterData $routerData, $macaddress, $registration_code, User $user) {
        $data = [
            'store_router_id' => $routerData->router_id,
            'macaddress' => $macaddress,
            'registration_code' => $registration_code,
            'store_account_id' => $user->user_identifier
        ];
        $response = $this->apiCall($data, 'STOREVPN_ROUTER_REGISTER');
        return $response;
    }

    public function verifyRouter($macaddress, $registration_code, User $user) {
        $data = [
            'macaddress' => $macaddress,
            'registration_code' => $registration_code,
            'store_account_id' => $user->user_identifier
        ];
        $response = $this->apiCall($data, 'STOREVPN_ROUTER_VERIFY');
        if ($response['success'] && isset($response['count']) && $response['count'] > 0) return TRUE;
            else return FALSE;
    }

    public function changeRouter(CustomerRouterData $routerData, User $user, $wifi_password = null) {
        $data = [
            'store_router_id' => $routerData->router_id,
            //'entry_server_id' => $routerData->vpn_server ? $routerData->vpn_server->server_id : null,
            //'location_id' => $routerData->location_id,
            'port' => $routerData->port,
            'lan_ip' => $routerData->lan_ip,
            'lan_netmask' => $routerData->lan_netmask,
            'dns_server1' => $routerData->dns_server1,
            'dns_server2' => $routerData->dns_server2,
            'wifi_ssid' => $routerData->wifi_ssid,
            'wifi_password' => $wifi_password,
            'store_account_id' => $user->user_identifier,
        ];
        if ($routerData->vpn_server) $data['entry_server_id'] = $routerData->vpn_server->server_id;
            else if ($routerData->location_id) $data['entry_location_id'] = $routerData->location_id;
        $response = $this->apiCall($data, 'STOREVPN_ROUTER_CHANGE');
        return $response;
    }

    public function saveRouterData(RecurlyProducts $plan, $macaddress, $activation_code, $store_router_id, CustomerVPNData $vpn_data, $entry_location_id, User $user, $uuid, $data = []) {
        $customerRouterData = CustomerRouterData::create([
            'uuid' => $uuid,
            'customer_id' => $user->id,
            'plan_id' => $plan->id,
            'router_id' => $store_router_id,
            'vpn_server_id' => $vpn_data ? $vpn_data->id : null,
            'location_id' => (!$vpn_data && $entry_location_id) ? $entry_location_id : null,
            'macaddress' => $macaddress,
            'activation_code' => $activation_code,
            'port' => isset($data['port']) ? $data['port'] : 1194,
            'lan_ip' => isset($data['lan_ip']) ? $data['lan_ip'] : '10.3.2.1',
            'lan_netmask' => isset($data['lan_netmask']) ? $data['lan_netmask'] : '255.255.255.0',
            'dns_server1' => isset($data['dns_server1']) ? $data['dns_server1'] : '208.67.222.222',
            'dns_server2' => isset($data['dns_server2']) ? $data['dns_server2'] : '208.67.220.220',
            'wifi_ssid' => isset($data['wifi_ssid']) ? $data['wifi_ssid'] : 'VPNSTARS',
            'enabled' => 1,
        ]);
        return $customerRouterData;
    }

    public function updateProvisionedRouterData(CustomerRouterData $routerData, CustomerVPNData $vpn_data = null, $entry_location_id, $macaddress, $activation_code, $data = []) {
        $routerData->vpn_server_id = $vpn_data ? $vpn_data->id : null;
        $routerData->location_id = (!$vpn_data && $entry_location_id) ? $entry_location_id : null;
        $routerData->macaddress = $macaddress;
        $routerData->activation_code = $activation_code;
        $routerData->port = isset($data['port']) ? $data['port'] : 1194;
        $routerData->lan_ip = isset($data['lan_ip']) ? $data['lan_ip'] : '10.3.2.1';
        $routerData->lan_netmask = isset($data['lan_netmask']) ? $data['lan_netmask'] : '255.255.255.0';
        $routerData->dns_server1 = isset($data['dns_server1']) ? $data['dns_server1'] : '208.67.222.222';
        $routerData->dns_server2 = isset($data['dns_server2']) ? $data['dns_server2'] : '208.67.220.220';
        $routerData->wifi_ssid = isset($data['wifi_ssid']) ? $data['wifi_ssid'] : 'VPNSTARS';
        $routerData->enabled = 1;
        $routerData->queued = 0;
        $routerData->save();
    }

    public function saveQueuedRouter(RecurlyProducts $plan, $store_router_id, CustomerVPNData $vpn_data = null, $location_id = null, User $user, $uuid) {
        $customerRouterData = CustomerRouterData::create([
          'uuid' => $uuid,
          'customer_id' => $user->id,
          'plan_id' => $plan->id,
          'router_id' => $store_router_id,
          'vpn_server_id' => $vpn_data ? $vpn_data->id : null,
          'location_id' => $location_id,
          'enabled' => 0,
          'queued' => 1
        ]);
        return $customerRouterData;
    }

    public function enableRouter(PurchasePlans $purchase_plan, User $user) {
        foreach($purchase_plan->router_data as $router_data) {
            if($router_data && $router_data->enabled == 0) {
                $data = array(
                    "store_router_id"    =>  $router_data->router_id,
                    "store_account_id"  =>  $user->user_identifier,
                );
                $response = $this->apiCall($data, 'STOREVPN_ROUTER_ENABLE');
                if ($response['success']) $router_data->update(['enabled'=>1, 'expired'=>0]);
            }
        }
        return TRUE;
    }

    public function disableRouter(PurchasePlans $purchase_plan, User $user) {
        foreach($purchase_plan->router_data as $router_data) {
            if ($router_data && $router_data->enabled == 1) {
                $data = array(
                    "store_router_id"    =>  $router_data->router_id,
                    "store_account_id"  =>  $user->user_identifier,
                );
                $response = $this->apiCall($data, 'STOREVPN_ROUTER_DISABLE');
                if ($response['success']) $router_data->update(['enabled'=>0, 'expired'=>0]);
            }
        }
        return TRUE;
    }

    public function loadLocations(User $user) {
        $data = [
          'store_account_id' => $user->user_identifier,
        ];
        $response = $this->apiCall($data, 'STOREVPN_ROUTER_LOCATIONS', 'GET');
        return $response;
    }
}
