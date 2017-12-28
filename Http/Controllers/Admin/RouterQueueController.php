<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerRouterData;
use App\Models\PurchasePlans;
use App\Services\StoreRouterService;
use App\User;
use Illuminate\Http\Request;
use Carbon;
use Illuminate\Support\Facades\Validator;


class RouterQueueController extends Controller
{

    protected $routerService;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(StoreRouterService $routerService) {
        $this->middleware('auth');
        $this->routerService = $routerService;
    }

    /**
     * Show the admin settings page.
     *
     * @return \Illuminate\Http\Response
     */
    public function getRouters() {
        return view('recurly.admin.routers-queue.list');
    }

    public function getRoutersTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'email'],
            2 => ['name'=>'uuid'],
            3 => ['name'=>'plan_name'],
            4 => ['name'=>'plan_code'],
            5 => ['name'=>'purchase_date'],
            6 => ['name'=>'expiration_date'],
        ];

        $query = CustomerRouterData::select('customers_router_data.id', 'customers_router_data.uuid',
          'users.email AS email', 'recurly_products.plan_name AS plan_name',
          'recurly_products.plan_code AS plan_code', 'customer_purchase_plans.purchase_date',
          'customer_purchase_plans.expiration_date')
          ->join('users', 'customers_router_data.customer_id', '=', 'users.id')
          ->join('recurly_products', 'customers_router_data.plan_id', '=', 'recurly_products.id')
          ->join('customer_purchase_plans', 'customer_purchase_plans.uuid', '=', 'customers_router_data.uuid')
          ->where('customers_router_data.queued', 1);

        $recordsTotal = $query->count();

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('recurly_products.plan_code', 'LIKE', '%'.$search['value'].'%')
                  ->orWhere('recurly_products.plan_name', 'LIKE', '%'.$search['value'].'%')
                  ->orWhere('users.email', 'LIKE', '%'.$search['value'].'%')
                  ->orWhere('customers_router_data.uuid', 'LIKE', '%'.$search['value'].'%');
            });
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
                $item->email,
                $item->uuid,
                $item->plan_name,
                $item->plan_code,
                $item->purchase_date,
                $item->expiration_date,
                '<a class="btn btn-default btn-sm" href="'.action('Admin\RouterQueueController@getRoutersView', $item->id).'"><span class="glyphicon glyphicon-edit"></span></a>',
            ];
        }
        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

    public function getRoutersView(CustomerRouterData $router) {
        $customer = User::find($router->customer_id);
        $subscription = PurchasePlans::where('uuid', $router->uuid)->first();
        return view('recurly.admin.routers-queue.view', [
            'router' => $router,
            'customer' => $customer,
            'subscription' => $subscription
        ]);
    }

    public function postRoutersProvision(CustomerRouterData $router, Request $request) {
        $validator = $this->provisionValidator($request->all());
        if ($validator->fails()) {
            $this->throwValidationException(
              $request, $validator
            );
        }

        $customer = User::find($router->customer_id);
        $macaddress = $request->get('macaddress');

        $data = [
          'port' => 1194,
          'lan_ip' => '10.3.2.1',
          'lan_netmask' => '255.255.255.0',
          'dns_server1' => '208.67.222.222',
          'dns_server2' => '208.67.220.220',
          'wifi_ssid' => 'VPNSTARS',
          'wifi_password' => bin2hex(openssl_random_pseudo_bytes(4)),
        ];

        $vpnData = $router->vpn_server;
        $vpn_server_id = $vpnData ? $vpnData->server_id : null;
        $entry_location_id = $router->location_id;
        $response = $this->routerService->provisionRouter($router->router_id, $vpn_server_id, $entry_location_id, $macaddress, $router->plan, $customer, $data);
        if (!$response['success'] || $response['success'] == FALSE) {
            return redirect()->back()->with('error', $response['error']);
        }
        $this->routerService->updateProvisionedRouterData($router, $vpnData, $entry_location_id, $response['macaddress'], $response['activation_code'], $data);

        return redirect()->action('Admin\RouterQueueController@getRouters')->with('success', 'Router has been provisioned.');
    }

    protected function provisionValidator(array $data = []) {
        $rules = [
          'macaddress' => 'required|max:255',
        ];
        return Validator::make($data, $rules);
    }

}
