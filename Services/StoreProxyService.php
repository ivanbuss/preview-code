<?php

namespace App\Services;

use App\Models\CustomerProxyData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\User;
use Carbon\Carbon;

class StoreProxyService extends APIService{

    public function __construct() {

    }

    public function saveProxyData(RecurlyProducts $plan, User $user, $uuid, $ip_list, $addresses, $protocol, $ip = null) {
        $serelizePorts = serialize($ip_list);
        $addressesArray = [];
        if ($addresses) {
            foreach($addresses as $id=>$address) {
                $addressesArray[$id] = [
                    'port' => $address->port,
                    'address' => $address->address,
                ];
            }
        }
        $serelizeAddresses = serialize($addressesArray);
        $customerProxyData = CustomerProxyData::create([
            'customer_id' => $user->id,
            'uuid' => $uuid,
            'plan_id' => $plan->id,
            'ip_list' => $serelizePorts,
            'addresses' => $serelizeAddresses,
            'authorized_ip_list' => '',
            'protocol' => $protocol,
            'rotation_period' => $plan->rotation_period,
            'anytime_ports' => $plan->anytime_ports,
            'type' => $plan->type,
            'location' => $plan->location,
            'region_changeable' => $plan->region_changeable,
            'enabled' => 1,
            'date_added' => Carbon::now(),
        ]);
        return $customerProxyData;
    }

    public function deleteProxyData(PurchasePlans $purchasePlan, User $user, $delete = TRUE) {
        $plan = $purchasePlan->plan;
        $proxy_data = CustomerProxyData::where('uuid', $purchasePlan->uuid)->where('customer_id', $purchasePlan->customer_id)->get();
        foreach($proxy_data as $proxy) {
            if ($plan->plan_type == "dedicated") {
                if ($proxy->type == 'dedicated_turbospin') {
                    $store_proxy_id = $purchasePlan->id . '-' . $proxy->plan_id . '-2';
                } else {
                    $store_proxy_id = $purchasePlan->id . '-' . $proxy->plan_id . '-1';
                }
            } else {
                $store_proxy_id = $purchasePlan->id . '-' . $proxy->plan_id;
            }
            $response = $this->deleteProxy($store_proxy_id, $user);
            if ($response['success'] == FALSE) {
                return $response;
            }
            if ($delete) {
                $proxy->delete();
            } else {
                $proxy->enabled = 0;
                $proxy->expired = 1;
                $proxy->save();
            }
        }
        return ['success'=>TRUE];
    }

    public function storeProxy($store_proxy_id, RecurlyProducts $plan, User $user, RecurlyProducts $parent_plan = null) {
        $data = array(
            "store_proxy_id" => $store_proxy_id,
            "number_of_ports" => $plan->anytime_ports,
            "number_of_threads" => $plan->anytime_threads,
            "type" => $plan->type,
            "region" => $plan->location,
            "region_changeable" => $plan->region_changeable ? $plan->region_changeable : FALSE,
            "rotation_period" => $plan->rotation_period,
            "store_account_id" => $user->user_identifier,
        );
        if ($plan->billing_type == 'trial') $data['duration'] = $plan->getDurationSeconds();

        if ($parent_plan) {
            if ($parent_plan->billing_type == 'trial') $data['duration'] = $parent_plan->getDurationSeconds();
            $data['number_of_ports'] = $plan->anytime_ports ? $plan->anytime_ports : $parent_plan->anytime_ports;
            $data['number_of_threads'] = $plan->anytime_threads ? $plan->anytime_threads : $parent_plan->anytime_threads;
            $data['region_changeable'] = $plan->region_changeable ? $plan->region_changeable : $parent_plan->region_changeable;
            $data['region'] = $plan->location ? $plan->location : $parent_plan->location;
        }
        $response = $this->apiCall($data);
        return $response;
    }

    public function deleteProxy($store_proxy_id, User $user) {
        $data = [
            'store_proxy_id' => $store_proxy_id,
            'store_account_id' => $user->user_identifier
        ];
        $response = $this->apiCall($data, 'STOREPROXY_DELETE');
        return $response;
    }

    public function createProxyService(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $purchasePlans = $this->savePurchasePlan($plan, $user, $uuid, $ip, $expiration_date, $coupon_code);
        if ($purchasePlans->status == PurchasePlans::PROVISIONING || $purchasePlans->local_status == PurchasePlans::PROVISIONING) return ['success'=>TRUE, 'purchase_plan'=>$purchasePlans];

        if ($plan->plan_type == "complex") {
            $bundleProducts = $plan->bundlePlans()->get();
            foreach ($bundleProducts as $bundleProduct) {
                $store_proxy_id = $purchasePlans->id . '-' . $bundleProduct->id;
                $response = $this->storeProxy($store_proxy_id, $bundleProduct, $user, $plan);
                if (!$response['success'] || $response['success'] == FALSE) {
                    $purchasePlans->local_status = PurchasePlans::ERROR;
                    $purchasePlans->save();
                    $response['purchase_plan'] = $purchasePlans;
                    return $response;
                }

                if (isset($response['ports']) && !empty($response['ports'])) {
                    $customerProxyData = $this->saveProxyData($bundleProduct, $user, $uuid, $response['ports'], $response['addresses'], $response['protocol'], $ip);
                    $response['proxydata'] = $customerProxyData;
                }
            }
        } elseif ($plan->plan_type == "dedicated") {
            $store_proxy_ids = [
                1 => $purchasePlans->id.'-'.$plan->id.'-1',
                2 => $purchasePlans->id.'-'.$plan->id.'-2',
            ];
            foreach($store_proxy_ids as $key=>$store_proxy_id) {
                if ($key == 2) $plan->type = 'dedicated_turbospin';
                $response = $this->storeProxy($store_proxy_id, $plan, $user);
                if (!$response['success'] || $response['success'] == FALSE) {
                    $purchasePlans->local_status = PurchasePlans::ERROR;
                    $purchasePlans->save();
                    $response['purchase_plan'] = $purchasePlans;
                    return $response;
                }

                if (isset($response['ports']) && !empty($response['ports'])) {
                    $customerProxyData = $this->saveProxyData($plan, $user, $uuid, $response['ports'], $response['addresses'], $response['protocol'], $ip);
                    $response['proxydata'][] = $customerProxyData;
                }
            }
        } else {
            $store_proxy_id = $purchasePlans->id.'-'.$plan->id;
            $response = $this->storeProxy($store_proxy_id, $plan, $user);
            if (!$response['success'] || $response['success'] == FALSE) {
                $purchasePlans->local_status = PurchasePlans::ERROR;
                $purchasePlans->save();
                $response['purchase_plan'] = $purchasePlans;
                return $response;
            }

            if (isset($response['ports']) && !empty($response['ports'])) {
                $customerProxyData = $this->saveProxyData($plan, $user, $uuid, $response['ports'], $response['addresses'], $response['protocol'], $ip);
                $response['proxydata'] = $customerProxyData;
            }
        }
        $purchasePlans->local_status = 'enabled';
        $purchasePlans->save();
        $response['purchase_plan'] = $purchasePlans;
        return $response;
    }

    public function upgradeProxyService(PurchasePlans $purchasePlan, RecurlyProducts $plan, User $user) {
        $response = $this->deleteProxyData($purchasePlan, $user);
        if (!$response['success'] || $response['success'] == FALSE) {
            return $response;
        }

        $ip = [$purchasePlan->ip_address];
        $response = $this->createProxyService($plan, $user, $purchasePlan->uuid, $ip);
        return $response;
    }

    public function createPurchasedProxyService(PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        if ($plan->plan_type == "complex") {
            $bundleProducts = $plan->bundlePlans()->get();
            foreach ($bundleProducts as $bundleProduct) {
                $store_proxy_id = $purchasePlans->id . '-' . $bundleProduct->id;
                $response = $this->storeProxy($store_proxy_id, $bundleProduct, $user, $plan);
                if (!$response['success'] || $response['success'] == FALSE) {
                    $purchasePlans->local_status = PurchasePlans::ERROR;
                    $purchasePlans->save();
                    return $response;
                }

                if (isset($response['ports']) && !empty($response['ports'])) {
                    $ip = [$purchasePlans->ip_address];
                    $customerProxyData = $this->saveProxyData($bundleProduct, $user, $purchasePlans->uuid, $response['ports'], $response['addresses'], $response['protocol'], $ip);
                    $response['proxydata'] = $customerProxyData;
                }
            }
        } else if ($plan->plan_type == 'dedicated') {
            $count = $this->getAvailableProxies($plan);
            if ($count && $count >= $plan->anytime_ports) {
                $store_proxy_ids = [
                    1 => $purchasePlans->id.'-'.$plan->id.'-1',
                    2 => $purchasePlans->id.'-'.$plan->id.'-2',
                ];
                foreach($store_proxy_ids as $key=>$store_proxy_id) {
                    if ($key == 2) $plan->type = 'dedicated_turbospin';
                    $response = $this->storeProxy($store_proxy_id, $plan, $user);
                    if (!$response['success'] || $response['success'] == FALSE) {
                        $purchasePlans->local_status = PurchasePlans::ERROR;
                        $purchasePlans->save();
                        return $response;
                    }

                    if (isset($response['ports']) && !empty($response['ports'])) {
                        $ip = [$purchasePlans->ip_address];
                        $customerProxyData = $this->saveProxyData($plan, $user, $purchasePlans->uuid, $response['ports'], $response['addresses'], $response['protocol'], $ip);
                        $response['proxydata'][] = $customerProxyData;
                    }
                }
            }
        } else {
            $store_proxy_id = $purchasePlans->id.'-'.$plan->id;
            $ports = FALSE;
            if ($plan->plan_type == "simple" && $plan->type == 'dedicated') {
                $count = $this->getAvailableProxies($plan);
                if ($count && $count >= $plan->anytime_ports) $ports = TRUE;
            }
            if (($plan->plan_type == "simple" && $plan->type == 'dedicated' && $ports == TRUE) || $plan->type != 'dedicated') {
                $response = $this->storeProxy($store_proxy_id, $plan, $user);
                if (!$response['success'] || $response['success'] == FALSE) {
                    $purchasePlans->local_status = PurchasePlans::ERROR;
                    $purchasePlans->save();
                    return $response;
                }

                if (isset($response['ports']) && !empty($response['ports'])) {
                    $ip = [$purchasePlans->ip_address];
                    $customerProxyData = $this->saveProxyData($plan, $user, $purchasePlans->uuid, $response['ports'], $response['addresses'], $response['protocol'], $ip);
                    $response['proxydata'] = $customerProxyData;
                }
            }
        }

        $purchasePlans->status = PurchasePlans::ACTIVE;
        $purchasePlans->local_status = 'enabled';
        $purchasePlans->save();

        $response['purchase_plan'] = $purchasePlans;
        return $response;
    }

    public function reactiveteProxyService(PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        if ($plan->plan_type == "complex") {
            foreach ($plan->bundlePlans as $bundleItem) {
                $proxy_data = CustomerProxyData::where('customer_id', $user->id)
                    ->where('plan_id', $bundleItem->id)
                    ->where('uuid', $purchasePlans->uuid)
                    ->first();
                if ($proxy_data) {
                    $store_proxy_id = $purchasePlans->id.'-'.$bundleItem->id;
                    $this->reactivateApiCall($store_proxy_id, $purchasePlans, $bundleItem, $user);
                    $proxy_data->enabled = 1;
                    $proxy_data->expired = 0;
                    $proxy_data->save();
                    $purchasePlans->local_status = 'enabled';
                    $purchasePlans->save();
                }
            }
        } else if ($plan->plan_type == "dedicated") {
            $proxy_datas = CustomerProxyData::where('customer_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('uuid', $purchasePlans->uuid)
                ->get();
            foreach($proxy_datas as $proxy_data) {
                if ($proxy_data) {
                    if ($proxy_data->type == 'dedicated') $store_proxy_id = $purchasePlans->id.'-'.$plan->id.'-1';
                        else if ($proxy_data->type == 'dedicated_turbospin') $store_proxy_id = $purchasePlans->id.'-'.$plan->id.'-2';
                    $this->reactivateApiCall($store_proxy_id, $purchasePlans, $plan, $user);
                    $proxy_data->enabled = 1;
                    $proxy_data->expired = 0;
                    $proxy_data->save();
                    $purchasePlans->local_status = 'enabled';
                    $purchasePlans->save();
                }
            }
        } else {
            $proxy_data = CustomerProxyData::where('customer_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('uuid', $purchasePlans->uuid)
                ->first();
            if ($proxy_data) {
                $store_proxy_id = $purchasePlans->id.'-'.$plan->id;
                $this->reactivateApiCall($store_proxy_id, $purchasePlans, $plan, $user);
                $proxy_data->enabled = 1;
                $proxy_data->expired = 0;
                $proxy_data->save();
                $purchasePlans->local_status = 'enabled';
                $purchasePlans->save();
            }
        }
    }

    public function reactivateApiCall($store_proxy_id, PurchasePlans $purchasePlans, RecurlyProducts $plan, User $user) {
        $url = env('STOREPROXY_ENABLE');
        $data = array(
            "store_proxy_id"    =>  $store_proxy_id,
            "store_account_id"  =>  $user->user_identifier,
        );

        $jsondata = json_encode($data);

        try {
            $response = curlWrap("storeproxy", $url, $jsondata, "POST");
            if (!isset($response)){
                throw new \Exception('Store proxy is not responding! Please try later Thanks');
            }
        } catch (\Exception $e){
            throw new \Exception($e->getMessage());
        }

        return $response;
    }

    public function savePurchasePlan(RecurlyProducts $plan, User $user, $uuid, $ip = null, $expiration_date = null, $coupon_code = null) {
        $status = PurchasePlans::ACTIVE; $client_ip = null;
        $local_status = 'enabled';
        if ($plan->type == 'dedicated' || ($plan->type == 'simple' && $plan->plan_type == "dedicated_simple")) {
            $count = $this->getAvailableProxies($plan);
            if (!$count || $count < $plan->anytime_ports) {
                $status = PurchasePlans::PROVISIONING;
                $local_status = PurchasePlans::PROVISIONING;
            }
        }

        if (!$expiration_date) {
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
        //if (!$expiration_date) $expiration_date = Carbon::now()->addSeconds($plan->getDurationSeconds());
        if ($ip) {
            if (is_array($ip) && isset($ip[0])) $client_ip = $ip[0];
                else $client_ip = $ip;
        }

        $purchasePlans = PurchasePlans::where('uuid', $uuid)->where('customer_id', $user->id)->first();
        if (!$purchasePlans) {
            $purchasePlans  = PurchasePlans::create([
                'uuid'			  =>  $uuid,
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

    public function getAvailableProxies(RecurlyProducts $plan) {
        $data = array(
            'region' => $plan->location,
        );
        $response = $this->apiCall($data, 'STOREPROXY_COUNT', 'GET');
        if (isset($response['count']) && $response['count']) return $response['count'];
            else return FALSE;
    }

    public function disableProxy(PurchasePlans $purchase_plan, User $user) {
        foreach($purchase_plan->proxy_data as $proxy_data) {
            if($proxy_data && $proxy_data->enabled == 1) {
                $plan = $purchase_plan->plan;
                if ($plan->plan_type == "dedicated") {
                    if ($proxy_data->type == 'dedicated_turbospin') {
                        $store_proxy_id = $purchase_plan->id.'-'.$proxy_data->plan_id . '-2';
                    } else {
                        $store_proxy_id = $purchase_plan->id.'-'.$proxy_data->plan_id . '-1';
                    }
                } else {
                    $store_proxy_id = $purchase_plan->id.'-'.$proxy_data->plan_id;
                }
                $data = array(
                    "store_proxy_id"    =>  $store_proxy_id,
                    "store_account_id"  =>  $user->user_identifier,
                );
                $response = $this->apiCall($data, 'STOREPROXY_DISABLE');
                if ($response['success']) $proxy_data->update(['enabled'=>0, 'expired'=>0]);
            }
        }
    }

    public function enableProxy(PurchasePlans $purchase_plan, User $user) {
        foreach($purchase_plan->proxy_data as $proxy_data) {
            if ($proxy_data && $proxy_data->enabled == 0) {
                $plan = $purchase_plan->plan;
                if ($plan->plan_type == "dedicated") {
                    if ($proxy_data->type == 'dedicated_turbospin') {
                        $store_proxy_id = $purchase_plan->id.'-'.$proxy_data->plan_id . '-2';
                    } else {
                        $store_proxy_id = $purchase_plan->id.'-'.$proxy_data->plan_id . '-1';
                    }
                } else {
                    $store_proxy_id = $purchase_plan->id.'-'.$proxy_data->plan_id;
                }
                $data = array(
                    "store_proxy_id"    =>  $store_proxy_id,
                    "store_account_id"  =>  $user->user_identifier,
                );
                $response = $this->apiCall($data, 'STOREPROXY_ENABLE');
                if ($response['success']) $proxy_data->update(['enabled'=>1, 'expired'=>0]);
            }
        }
    }
}
