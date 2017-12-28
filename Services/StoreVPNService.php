<?php

namespace App\Services;

use App\Models\City;
use App\Models\CustomerProxyData;
use App\Models\CustomerRouterData;
use App\Models\CustomerVPNData;
use App\Models\CustomerVPNServerUsers;
use App\Models\CustomerVPNUsers;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\Models\ShippingData;
use App\User;
use Carbon\Carbon;

class StoreVPNService extends APIService{

    public function __construct() {

    }

    public function createVPNService(User $user) {
        $data = array(
            "store_service_id" => $user->user_identifier,
            "service_name" => 'Dedicated VPN',
            "is_subscription" => 1,
            "is_freetrial" => 0,
            "is_entryexit" => 1,
            "standard_enabled" => 1,
            "exotic_enabled" => 0,
            "store_account_id" => $user->user_identifier,
        );
        $response = $this->apiCall($data, 'STOREVPN_CREATE');
        return $response;
    }

    public function createVPNServer(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $purchasePlans = $this->savePurchasePlan($plan, $user, $uuid, $ip, $expiration_date, $coupon_code);
        if ($purchasePlans->status == PurchasePlans::PROVISIONING || $purchasePlans->local_status == PurchasePlans::PROVISIONING) return ['success'=>TRUE, 'purchase_plan'=>$purchasePlans];

        $store_server_id = $purchasePlans->id.'-'.$plan->id;
        $response = $this->allocateVPNServer($store_server_id, $plan, $user);
        if ((!$response['success'] || $response['success'] == FALSE) && !isset($response['server']) || !$response['server']) {
            $purchasePlans->local_status = PurchasePlans::ERROR;
            $purchasePlans->save();
            $response['purchase_plan'] = $purchasePlans;
            return $response;
        }

        $server = $response['server'];
        $customerVPNData = $this->saveVPNData($plan, $server, $user, $uuid, $ip);
        $response['vpndata'] = $customerVPNData;

        $purchasePlans->local_status = 'enabled';
        $purchasePlans->save();
        $response['purchase_plan'] = $purchasePlans;
        return $response;
    }

    public function createPurchaseVPNServer(PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        $count = $this->getAvailableVPN($plan, $user);
        if ($count && $count >= $plan->anytime_ports) {
            $store_server_id = $purchasePlans->id.'-'.$plan->id;
            $response = $this->allocateVPNServer($store_server_id, $plan, $user);
            if ((!$response['success'] || $response['success'] == FALSE) && empty($response['server'])) {
                $purchasePlans->local_status = PurchasePlans::ERROR;
                $purchasePlans->save();
                $response['purchase_plan'] = $purchasePlans;
                return $response;
            }

            $server = $response['server']; $ip = [$purchasePlans->ip_address];
            $customerVPNData = $this->saveVPNData($plan, $server, $user, $purchasePlans->uuid, $ip);
            $response['vpndata'] = $customerVPNData;

            $purchasePlans->status = PurchasePlans::ACTIVE;
            $purchasePlans->local_status = 'enabled';
            $purchasePlans->save();
            $response['purchase_plan'] = $purchasePlans;
            return $response;
        } else {
            return ['success'=>FALSE, 'error'=>'Not available VPN Servers in this city.'];
        }
    }

    public function allocateVPNServer($store_server_id, RecurlyProducts $plan, User $user) {
        $data = [
            'store_server_id' => $store_server_id,
            'city_id' => $plan->city,
            'maxconn' => $plan->vpn_users,
            'store_account_id' => $user->user_identifier,
        ];
        if ($plan->billing_type == 'trial') $data['is_freetrial'] = TRUE;
        $response = $this->apiCall($data, 'STOREVPN_SERVER_ALLOCATE');
        return $response;
    }

    public function createVPNUser($username, $password, User $user, $is_main = FALSE) {
        $data = [
            'store_service_id' => $user->user_identifier,
            'vpn_username' => $username,
            'vpn_password' => $password,
            'is_main' => $is_main ? 1 : 0,
            'enabled' => 1,
            'store_account_id' => $user->user_identifier,
        ];
        $response = $this->apiCall($data, 'STOREVPN_USER_CREATE');
        if (!$response['success'] || $response['success'] == FALSE) {
            if ($response['error'] == 'Application error'/* || $response['error'] == 'VPN user already exists'*/) {
                $data = [
                    'vpn_username' => $username,
                    'store_account_id' => $user->user_identifier
                ];
                $delete_response = $this->apiCall($data, 'STOREVPN_USER_DELETE');
            }
            return $response;
        }
        $vpnUser = $this->storeVPNUser($username, $password, $user, $is_main);
        $response['vpnUser'] = $vpnUser;
        return $response;
    }

    public function updateVPNUser(CustomerVPNUsers $vpnUser, $status, User $user) {
        $data = [
            'vpn_username' => $vpnUser->vpn_username,
            'enabled' => $status,
            'store_account_id' => $user->user_identifier,
        ];
        $response = $this->apiCall($data, 'STOREVPN_USER_UPDATE');
        return $response;
    }

    public function updateVPNUserPassword(CustomerVPNUsers $vpnUser, $password, User $user) {
        $data = [
            'username' => $vpnUser->vpn_username,
            'old_password' => $vpnUser->vpn_password,
            'new_password' => $password,
        ];
        $response = $this->apiCall($data, 'STOREVPN_USER_PASSWORD_CHANGE');
        return $response;
    }

    public function storeVPNUser($username, $password, User $user, $is_main = 0, $enabled = 1) {
        $vpnUser = CustomerVPNUsers::create([
            'customer_id' => $user->id,
            'store_service_id' => $user->user_identifier,
            'vpn_username' => $username,
            'vpn_password' => bcrypt($password),
            'is_main' => $is_main,
            'enabled' => $enabled
        ]);
        return $vpnUser;
    }

    public function deleteVPNUser(CustomerVPNUsers $vpnUser, User $user) {
        $data = [
            'vpn_username' => $vpnUser->vpn_username,
            'store_account_id' => $user->user_identifier
        ];
        $response = $this->apiCall($data, 'STOREVPN_USER_DELETE');
        return $response;
    }

    public function savePurchasePlan(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $status = PurchasePlans::ACTIVE; $client_ip = '';
        $local_status = 'enabled';
        if ($plan->plan_type == 'vpn_dedicated') {
            $count = $this->getAvailableVPN($plan, $user);
            if (!$count || $count < $plan->anytime_ports) {
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

    public function getAvailableVPN(RecurlyProducts $plan, User $user) {
        $data = array(
            'city_id' => $plan->city,
            'store_account_id' => $user->user_identifier,
        );
        $response = $this->apiCall($data, 'STOREVPN_SERVER_COUNT', 'GET');
        if (isset($response['count']) && !empty($response['count'])) return $response['count'];
            else return FALSE;
    }

    public function assignUser(CustomerVPNUsers $vpnUser, CustomerVPNData $vpnServer, User $user) {
        $data = [
            'store_server_id' => $vpnServer->server_id,
            'vpn_username' => $vpnUser->vpn_username,
            'store_account_id' => $user->user_identifier,
        ];
        $response = $this->apiCall($data, 'STOREVPN_USER_ASSIGN');
        if (!$response['success'] || $response['success'] == FALSE) {
            return $response;
        }
        $connection = $this->createUserServerConnection($vpnUser, $vpnServer);
        $response['connection'] = $connection;
        return $response;
    }

    public function unassignUser(CustomerVPNServerUsers $serversUser, User $user) {
        $data = [
            'store_server_id' => $serversUser->vpnserver->server_id,
            'vpn_username' => $serversUser->vpnuser->vpn_username,
            'store_account_id' => $user->user_identifier,
        ];
        $response = $this->apiCall($data, 'STOREVPN_USER_UNASSIGN');
        if (!$response['success'] || $response['success'] == FALSE) {
            return $response;
        }
        $this->deleteUserServerConnection($serversUser);
        return $response;
    }

    public function createUserServerConnection(CustomerVPNUsers $vpnUser, CustomerVPNData $vpnServer) {
        $connection = CustomerVPNServerUsers::create([
            'vpn_data_id' => $vpnServer->id,
            'vpn_user_id' => $vpnUser->id,
        ]);
        return $connection;
    }

    public function deleteUserServerConnection(CustomerVPNServerUsers $serversUser) {
        $serversUser->delete();
        return TRUE;
    }

    public function upgradeServer(CustomerVPNData $vpnServer, RecurlyProducts $plan, User $user) {
        $data = [
            'store_server_id' => $vpnServer->server_id,
            'maxconn' => $plan->vpn_users,
            'store_account_id' => $user->user_identifier,
        ];
        $response = $this->apiCall($data, 'STOREVPN_SERVER_UPGRADE');
        if (!$response['success'] || $response['success'] == FALSE) {
            return $response;
        }
        $this->updateMaxUsers($vpnServer, $plan);
        return $response;
    }

    public function saveVPNData(RecurlyProducts $plan, $server, User $user, $uuid, $ip = null) {
        $customerVPNData = CustomerVPNData::create([
            'customer_id' => $user->id,
            'uuid' => $uuid,
            'plan_id' => $plan->id,
            'server_id' => $server->id,
            'server_region' => $server->region,
            'server_city' => $server->city,
            'server_ip_address' => $server->ip_address,
            'max_users' => $plan->vpn_users,
            'authorized_ip_list' => serialize($ip),
            'rotation_period' => $plan->rotation_period,
            'type' => $plan->type,
            'location' => $plan->city,
            'enabled' => 1,
            'date_added' => Carbon::now(),
        ]);
        return $customerVPNData;
    }

    public function updateMaxUsers(CustomerVPNData $vpn_data, RecurlyProducts $plan) {
        $vpn_data->plan_id = $plan->id;
        $vpn_data->max_users = $plan->vpn_users;
        $vpn_data->save();
        return TRUE;
    }

    public function disableVPNServer(PurchasePlans $purchase_plan, User $user) {
        foreach($purchase_plan->vpn_data as $vpn_data) {
            if ($vpn_data && $vpn_data->enabled == 1) {
                $data = array(
                    "store_server_id"    =>  $vpn_data->server_id,
                    "store_account_id"  =>  $user->user_identifier,
                );
                $response = $this->apiCall($data, 'STOREVPN_SERVER_DISABLE');
                if ($response['success']) $vpn_data->update(['enabled'=>0, 'expired'=>0]);
            }
        }
        return TRUE;
    }

    public function deleteVPNData(PurchasePlans $purchase_plan, User $user, $delete = TRUE) {
        foreach($purchase_plan->vpn_data as $vpn_data) {
            if ($vpn_data) {
                $response = $this->cancelVPNServer($vpn_data->server_id, $user);
                if (!$response['success'] || $response['success'] == FALSE) {
                    return $response;
                }
                foreach($vpn_data->usersAssigned as $serversUser) {
                    $serversUser->delete();
                }
                
                if ($delete) {
                    $vpn_data->delete();
                } else {
                    $vpn_data->enabled = 0;
                    $vpn_data->expired = 1;
                    $vpn_data->save();
                }
            }
        }
        return TRUE;
    }

    public function cancelVPNServer($server_id, User $user) {
        $data = array(
            "store_server_id" => $server_id,
            "store_account_id" => $user->user_identifier,
        );
        $response = $this->apiCall($data, 'STOREVPN_SERVER_CANCEL');
        return $response;
    }

    public function enableVPNServer(PurchasePlans $purchase_plan, User $user) {
        foreach($purchase_plan->vpn_data as $vpn_data) {
            if($vpn_data && $vpn_data->enabled == 0) {
                $data = array(
                    "store_server_id"    =>  $vpn_data->server_id,
                    "store_account_id"  =>  $user->user_identifier,
                );
                $response = $this->apiCall($data, 'STOREVPN_SERVER_ENABLE');
                if ($response['success']) $vpn_data->update(['enabled'=>1, 'expired'=>0]);
            }
        }
        return TRUE;
    }

    public function downloadUser(CustomerVPNUsers $vpnUser, CustomerVPNData $vpnServer, $protocol, User $user, $location = null) {
        if (!$location) $location = $vpnServer->location;
        $data = [
            'vpn_username' => $vpnUser->vpn_username,
            'entry_server_id' => $vpnServer->server_id,
            'location_id' => $location,
            'vpn_protocol' => $protocol,
            'store_account_id' => $user->user_identifier,
        ];

        $response = $this->apiCall($data, 'STOREVPN_USER_DOWNLOAD');
        return $response;
    }

    public function loadLocations(User $user, CustomerVPNUsers $vpnUser = null) {
        $data = [
          'store_account_id' => $user->user_identifier,
        ];
        if ($vpnUser) $data['vpn_username'] = $vpnUser->vpn_username;
        $response = $this->apiCall($data, 'STOREVPN_USER_LOAD_LOCATIONS', 'GET');
        return $response;
    }

    public function reactivateService(PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        $vpn_data = CustomerVPNData::where('customer_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('uuid', $purchasePlans->uuid)
            ->first();
        if ($vpn_data) {
            $this->enableVPNServer($purchasePlans, $user);
            $vpn_data->enabled = 1;
            $vpn_data->expired = 0;
            $vpn_data->save();
        }
        return TRUE;
    }
}
