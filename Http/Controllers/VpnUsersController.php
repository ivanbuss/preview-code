<?php

namespace App\Http\Controllers;

use App\Models\CustomerVPNData;
use App\Models\CustomerVPNServerUsers;
use App\Models\CustomerVPNUsers;
use App\Services\StoreProxyService;
use App\Services\StoreVPNService;
use App\User;
use Chumper\Zipper\Zipper;
use Illuminate\Http\Request;

use App\Http\Requests;
use Carbon\Carbon;

use App\Models\CustomerProxyData;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;


class VpnUsersController extends Controller
{

    protected $vpnService;

    function __construct(StoreVPNService $vpnService) {
        $this->vpnService = $vpnService;
    }

    public function getUsersList(Request $request) {
        return view('recurly.vpn.users.list');
    }

    public function getUsersListTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'vpn_username'],
            2 => ['name'=>'created_at'],
        ];

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = CustomerVPNUsers::select('customers_vpn_users.*')->where('customer_id', $request->user()->id);

        $recordsTotal = $query->count();

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where('vpn_username', 'LIKE', '%'.$search['value'].'%');
        }
        $recordsFiltered = $query->count();

        $orders = $request->get('order') ? $request->get('order') : [];
        $this->dataTableSorting($query, $columns, $orders);

        $length = $request->get('length') ? $request->get('length') : 10;
        $start = $request->get('start') ? $request->get('start') : 0;
        $draw = $request->get('draw') ? $request->get('draw') : 1;

        if ($length != -1) {
            $query->offset($start)->limit($length);
        }
        $collective = $query->get();

        $items = [];
        foreach($collective as $item) {
            $items[] = [
                $item->id,
                $item->vpn_username,
                $item->created_at->format('F jS Y g:i A'),
                $item->is_main == 0 ? view('recurly.vpn.users.user_change_status', ['user'=>$item])->render() : 'Active',
                '<a data-remote="false" data-toggle="modal" data-target="#passwordModal" class="btn btn-default" href="'.action('VpnUsersController@getPasswordUpdate', $item->id).'">Change Password</a>',
                $item->is_main == 0 ? view('recurly.vpn.users.user_delete_button', ['user'=>$item])->render() : '',
            ];
        }
        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

    public function postCreate(Request $request) {
        $validator = $this->userValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }
        $vpn_username = $request->get('vpn_username');
        $vpn_password = $request->get('vpn_password');
        $customer = $request->user();

        $response = $this->vpnService->createVPNUser($vpn_username, $vpn_password, $customer);
        if ($response['success']) {
            return redirect()->back()->with('success', 'VPN user created successfully');
        } else {
            return redirect()->back()->withErrors([
                'error' => isset($response['error']) ? $response['error'] : 'Error with creating vpn user',
            ]);
        }
    }

    public function postStatusUpdate(CustomerVPNUsers $vpnUser, Request $request) {
        if (($request->user()->id != $vpnUser->customer_id) || $vpnUser->isMain()) abort(404);
        $status = $request->get('status') ? 1 : 0;
        $response = $this->vpnService->updateVPNUser($vpnUser, $status, $request->user());
        if ($response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['message']);
        }

        $vpnUser->enabled = $status;
        $vpnUser->save();

        if ($status) return redirect()->back()->with('success', 'VPN user has been activated.');
            else return redirect()->back()->with('success', 'VPN user has been disabled.');
    }

    public function getPasswordUpdate(CustomerVPNUsers $vpnUser, Request $request) {
        return view('recurly.vpn.users.user_change_password', ['vpnUser'=>$vpnUser]);
    }
    public function postPasswordUpdate(CustomerVPNUsers $vpnUser, Request $request) {
        if ($request->user()->id != $vpnUser->customer_id) abort(404);
        $password = $request->get('vpn_password');
        $response = $this->vpnService->updateVPNUserPassword($vpnUser, $password, $request->user());
        if ($response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['message']);
        }

        $vpnUser->vpn_password = bcrypt($password);
        $vpnUser->save();

        return redirect()->back()->with('success', 'VPN user password has been changed.');
    }

    public function delete(CustomerVPNUsers $vpnUser, Request $request) {
        if ($request->user()->id != $vpnUser->customer_id) abort(404);
        $response = $this->vpnService->deleteVPNUser($vpnUser, $request->user());
        if ($response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['error']);
        }
        $vpnUser->delete();

        return redirect()->back()->with('success', 'VPN User has been deleted.');
    }

    public function getAssignUser($uuid, $plan_id = null, Request $request) {
        $user_id = $request->user()->id;
        $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->first();
        $plan = $purchase_plan->plan;
        if ($plan->plan_type == 'package') {
            $bundle_plans = $plan->bundlePlans()->get();
            foreach($bundle_plans as $bundle_plan) {
                if ($bundle_plan->id == $plan_id) $plan = $bundle_plan;
            }
        }

        if ($purchase_plan->status == PurchasePlans::ACTIVE || $purchase_plan->status == PurchasePlans::CANCELED) {
            $vpnData = CustomerVPNData::where('uuid', $uuid)
                ->where('customer_id', $user_id)
                ->where('plan_id', $plan->id)
                ->firstOrFail();

            $connections = CustomerVPNServerUsers::where('vpn_data_id', $vpnData->id)->lists('vpn_user_id');
            $vpnUsersQuery = CustomerVPNUsers::where('customer_id', $user_id)->where('enabled', 1);
            if ($connections) {
                $vpnUsersQuery->whereNotIn('id', $connections);
            }
            $vpnUsers = $vpnUsersQuery->lists('vpn_username', 'id');

            return view('recurly.vpn.users.server_user_assign', ['vpnUsers'=>$vpnUsers, 'vpnData'=>$vpnData]);
        }
        return view('recurly.vpn.users.server_user_assign_inactive');
    }

    public function postAssignUser($uuid, $plan_id = null, Request $request) {
        $user_id = $request->user()->id;
        $purchase_plan = PurchasePlans::where('customer_id', $user_id)->where('uuid', $uuid)->orderBy('id', 'desc')->firstOrFail();
        $plan = $purchase_plan->plan;
        if ($plan->plan_type == 'package') {
            $bundle_plans = $plan->bundlePlans()->get();
            foreach($bundle_plans as $bundle_plan) {
                if ($bundle_plan->id == $plan_id) $plan = $bundle_plan;
            }
        }

        if (($purchase_plan->status == PurchasePlans::ACTIVE || $purchase_plan->status == PurchasePlans::CANCELED) && $request->get('vpn_user')) {
            $vpnData = CustomerVPNData::where('uuid', $uuid)
                ->where('customer_id', $user_id)
                ->where('plan_id', $plan->id)
                ->first();
            $vpn_user = CustomerVPNUsers::where('customer_id', $user_id)->where('id', $request->get('vpn_user'))->first();
            if ($vpn_user) {
                $response = $this->vpnService->assignUser($vpn_user, $vpnData, $request->user());
                if ($response['success'] == FALSE) {
                    return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('error', $response['error']);
                }
                return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('success', 'User '.$vpn_user->vpn_username.' has beed assigned to server');
            }
        }
        return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan->id])->with('error', 'Error occurred');
    }

    public function postRemoveUser($uuid, $plan_id, CustomerVPNServerUsers $serversuser, Request $request) {
        if ($serversuser) {
            $vpnUser = $serversuser->vpnuser; $vpnServer = $serversuser->vpnserver;
            $plan = $vpnServer->plan;
            if ($request->user()->id != $vpnUser->customer_id || $plan->isTrialSimpleVPN()) abort(404);
            $response = $this->vpnService->unassignUser($serversuser, $request->user());
            if ($response['success'] == FALSE) {
                return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan_id])->with('error', $response['error']);
            }
            return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan_id])->with('success', 'User has beed removed from server');
        }
        return redirect()->action('RecurlyController@productDetailForCustomers', [$uuid, $plan_id])->with('error', 'Error occurred');
    }

    public function getVPNLocations(CustomerVPNServerUsers $vpnUserRel, Request $request) {
        $vpn_user = $vpnUserRel->vpnuser;
        $response = $this->vpnService->loadLocations($request->user(), $vpn_user);
        if ($response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['error']);
        }

        $high_secure_loc = []; $extreme_secure_loc = [];
        foreach($response['locations'] as $location) {
            if ($location->is_starcore) $extreme_secure_loc[] = $location;
                else $high_secure_loc[] = $location;
        }

        return view('recurly.vpn.users.profile_locations', [
            'vpn_user_name' => $vpn_user->vpn_username,
            'vpn_server_user_id' => $vpnUserRel->id,
            'high_secure_loc' => $high_secure_loc,
            'extreme_secure_loc' => $extreme_secure_loc,
        ]);
    }

    public function getDownloadLocationProfile(CustomerVPNServerUsers $vpnUserRel, $protocol, $location, Request $request) {
        return $this->downloadProfile($vpnUserRel, $protocol, $request->user(), $location);
    }

    public function getDownloadProfile(CustomerVPNServerUsers $vpnUserRel, $protocol, Request $request) {
        return $this->downloadProfile($vpnUserRel, $protocol, $request->user());
    }

    protected function downloadProfile(CustomerVPNServerUsers $vpnUserRel, $protocol, User $user, $location = null) {
        $vpnProtocol = null;
        if ($protocol == 'tcp') $vpnProtocol = 'openvpn_tcp';
            else if ($protocol == 'udp') $vpnProtocol = 'openvpn_udp';
        $vpnUser = $vpnUserRel->vpnuser;
        $vpnServer = $vpnUserRel->vpnserver;
        if ($vpnUser->customer_id != $user->id) return redirect()->back()->with('error', 'Error occurred');
        $response = $this->vpnService->downloadUser($vpnUser, $vpnServer, $vpnProtocol, $user, $location);
        if ($response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['error']);
        }

        if (!File::exists(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id)) {
            File::makeDirectory(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id, $mode = 0744, true, true);
        }

        $bytes_written = File::put(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/ProxyStars_'.($location? $location.'_' : '').$vpnServer->server_ip_address.'.ovpn', $response['profile']);
        if ($bytes_written === false) {
            return redirect()->back()->with('error', 'Error with creating .ovpn file');
        }
        $txt_content = $response['vpn_username'];
        $txt_content .= "\r\n";
        $txt_content .= $response['vpn_password'];
        $bytes_written = File::put(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/auth.txt', $txt_content);
        if ($bytes_written === false) {
            return redirect()->back()->with('error', 'Error with creating .txt file');
        }

        $zipper = new Zipper;
        $zipper->make(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/vpnstars_'.($location? $location.'_' : '').$vpnServer->server_ip_address.'_'.$protocol.'.zip')
            ->add([
                base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/ProxyStars_'.($location? $location.'_' : '').$vpnServer->server_ip_address.'.ovpn',
                base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/auth.txt'
            ])->close();
        File::delete(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/ProxyStars_'.($location? $location.'_' : '').$vpnServer->server_ip_address.'.ovpn');
        File::delete(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/auth.txt');

        return response()->download(base_path() . '/storage/app/public/vpn_profiles/'.$vpnUser->customer_id.'/vpnstars_'.($location? $location.'_' : '').$vpnServer->server_ip_address.'_'.$protocol.'.zip')->deleteFileAfterSend(true);
    }

    protected function userValidator(array $data) {
        $rules = [
            'vpn_username' => 'string',
            'vpn_password' => 'string',
        ];
        return Validator::make($data, $rules);
    }
}
