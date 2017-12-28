<?php

namespace App\Services;

use App\Models\CustomerProxyData;
use App\Models\ErrorLog;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\User;
use Carbon\Carbon;

class APIService {

    public function __construct() {

    }

    public function apiCall(array $data, $action = 'STOREPROXY_CREATE', $method = 'POST') {
        try {
            $jsondata = json_encode($data);
            $url = env($action);
            if ($method == 'GET') {
                $url = $url .'?'.http_build_query($data);
            }

            $response = curlWrap("storeproxy", $url, $jsondata, $method);
            if (!$response) {
                $item = ErrorLog::create([
                  'code' => 'api_error',
                  'message' => $action,
                  'body' => 'API is not responding',
                ]);
                return ['success'=>FALSE, 'error'=>'API is not responding! Please try later Thanks.'];
            }

            $message = isset($response->message) ? $response->message : null;
            $message .= '<pre>'.print_r($data, TRUE).'</pre>';
            if ($response->http_code >= 400 && $message) {
                $item = ErrorLog::create([
                  'code' => 'api_error',
                  'message' => $action,
                  'body' => $message,
                ]);
                return ['success'=>FALSE, 'error'=>$message];
            }

            $userports = isset($response->ports) ? $response->ports : [];
            $addresses = isset($response->addresses) ? $response->addresses : [];
            $protocol = isset($response->protocol) ? $response->protocol : [];
            $count = isset($response->count) ? $response->count : null;
            $server = isset($response->server) ? $response->server : null;

            $profile = isset($response->profile) ? $response->profile : null;
            $vpn_username = isset($response->vpn_username) ? $response->vpn_username : null;
            $vpn_password = isset($response->vpn_password) ? $response->vpn_password : null;

            $activation_code = isset($response->activation_code) ? $response->activation_code : null;
            $macaddress = isset($response->macaddress) ? $response->macaddress : null;

            $locations = isset($response->locations) ? $response->locations : null;
            $free_locations = isset($response->free_locations) ? $response->free_locations : null;
            $servers = isset($response->servers) ? $response->servers : null;

            return [
                'success' => TRUE,
                'message'=>isset($response->message) ? $response->message : null,
                'ports' => $userports,
                'addresses' => $addresses,
                'protocol' => $protocol,
                'count' => $count,
                'server' => $server,
                'profile' => $profile,
                'vpn_username' => $vpn_username,
                'vpn_password' => $vpn_password,
                'activation_code' => $activation_code,
                'macaddress' => $macaddress,
                'locations' => $locations,
                'free_locations' => $free_locations,
                'servers' => $servers,
            ];
        } catch (Exception $e) {
            return ['success'=>FALSE, 'error'=>'Store API is not responding! Please try later Thanks'];
        }
    }

}
