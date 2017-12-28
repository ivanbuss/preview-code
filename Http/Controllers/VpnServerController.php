<?php

namespace App\Http\Controllers;

use App\Models\CustomerVPNData;
use App\Models\CustomerVPNServerUsers;
use App\Models\CustomerVPNUsers;
use App\Services\StoreProxyService;
use App\Services\StoreVPNService;
use Illuminate\Http\Request;

use App\Http\Requests;
use Carbon\Carbon;

use App\Models\CustomerProxyData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class VpnServerController extends Controller
{

    protected $vpnService;

    function __construct(StoreVPNService $vpnService) {
        $this->vpnService = $vpnService;
    }

    public function postUpgrade($uuid, Request $request) {
        $user_id = $request->user()->id;
        $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->first();
        $plan = $purchase_plan->plan;
        if ($purchase_plan->status == PurchasePlans::ACTIVE || $purchase_plan->status == PurchasePlans::CANCELED) {
            $vpnData = CustomerVPNData::where('uuid', $uuid)
                ->where('customer_id', $user_id)
                ->where('plan_id', $plan->id)
                ->first();
            $maxconn = $vpnData->max_users + 10;
            $response = $this->vpnService->upgradeServer($vpnData, $maxconn, $request->user());
            if ($response['success'] == FALSE) {
                return redirect()->back()->with('error', $response['error']);
            }
            return redirect()->back()->with('success', 'VPN Server has been upgraded');
        }
    }

}
