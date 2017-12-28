<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBundleProductRequest;
use App\Http\Requests\CreateCategoryRequest;
use App\Http\Requests\CreateRecurlyProductRequest;
use App\Models\Bundles;
use App\Models\City;
use App\Models\CustomerProxyData;
use App\Models\CustomerVPNData;
use App\Models\RecurlyCategories;
use App\Models\RecurlyProducts;
use App\Models\Regions;
use App\Services\RecurlyService;
use App\Services\Settings;
use App\User;
use App\Models\PurchasePlans;
use Carbon\Carbon;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Mockery\CountValidator\Exception;
use Recurly_Client;
use Recurly_NotFoundError;
use Recurly_Plan;
use Recurly_PlanList;
use App\Helpers;

class ProductsController extends Controller
{

    protected $recurlyService;
	protected $settings;

    function __construct(RecurlyService $recurlyService, Settings $settings) {
        $this->recurlyService = $recurlyService;
		$this->settings = $settings;
    }

    public function recurlyProducts(Request $request) {
        $parent_recurly_categories  = RecurlyCategories::getTopParentList();
        $product_types = product_types_list();
        $sorting_options = recurly_sorting_options();
        $child1Categories = []; $child2Categories = []; $child3Categories = []; $child4Categories = [];

        if (session('parentCategory')) {
            $child1Categories  = RecurlyCategories::where('parent_category_id', session('parentCategory'))->lists('name','id')->all();
        }
        if (session('child1Category')) {
            $child2Categories  = RecurlyCategories::where('parent_category_id', session('child1Category'))->lists('name','id')->all();
        }
        if (session('child2Category')) {
            $child3Categories  = RecurlyCategories::where('parent_category_id', session('child2Category'))->lists('name','id')->all();
        }
        if (session('child3Category')) {
            $child4Categories  = RecurlyCategories::where('parent_category_id', session('child3Category'))->lists('name','id')->all();
        }
        return view('recurly.admin.products.recurly_product')
            ->with('parent_recurly_categories', $parent_recurly_categories)
            ->with('product_types', $product_types)
            ->with('sorting_options', $sorting_options)
            ->with('filterRequestType', session('filterRequestType'))
            ->with('filterRequestPlan', session('filterRequestPlan'))
            ->with('filterRequestCategory', session('filterRequestCategory'))
            ->with('parentCategory', session('parentCategory'))
            ->with('child1Category', session('child1Category'))
            ->with('child2Category', session('child2Category'))
            ->with('child3Category', session('child3Category'))
            ->with('child4Category', session('child4Category'))
            ->with('product_rows', session('product_rows'))
            ->with('product_order', session('filter_order'))
            ->with('child1Categories', $child1Categories)
            ->with('child2Categories', $child2Categories)
            ->with('child3Categories', $child3Categories)
            ->with('child4Categories', $child4Categories);
    }

    public function recurlyProductsTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'plan_code'],
            2 => ['name'=>'plan_name'],
            3 => ['name'=>'plan_type'],
            4 => ['name'=>'category_id'],
            5 => ['name'=>'plan_availability'],
            6 => ['name'=>'billing_type'],
            7 => ['name'=>'duration'],
            8 => ['name'=>'plan_quantity'],
            9 => ['name'=>'unit_of_measure'],
            10 => ['name'=>'region_changeable'],
            11 => ['name'=>'region'],
            12 => ['name'=>'price'],
        ];

        $parent_cat = $request->get('parentCategory') ? $request->get('parentCategory') : null;
        session(['parentCategory' => $parent_cat]);
        $child1_cat = $request->get('child1Category') ? $request->get('child1Category') : null;
        session(['child1Category' => $child1_cat]);
        $child2_cat = $request->get('child2Category') ? $request->get('child2Category') : null;
        session(['child2Category' => $child2_cat]);
        $child3_cat = $request->get('child3Category') ? $request->get('child3Category') : null;
        session(['child3Category' => $child3_cat]);
        $child4_cat = $request->get('child4Category') ? $request->get('child4Category') : null;
        session(['child4Category' => $child4_cat]);
        $rows = $request->get('length') ? $request->get('length') : 10;
        session(['product_rows' => $rows]);

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = RecurlyProducts::select('recurly_products.*', 'regions.name AS region', 'cities.name AS city_name')
            ->leftjoin('regions', 'recurly_products.location', '=', 'regions.rid')
            ->leftjoin('cities', 'recurly_products.city', '=', 'cities.cid');

        $recordsTotal = $query->count();

        $type = $request->get('filterRequestType') ? $request->get('filterRequestType') : null;
        session(['filterRequestType' => $type]);
        if ($type) {
            switch ($type) {
                case 'package':
                    $query->where('plan_type', 'package');
                    break;
                case 'proxy_complex':
                    $query->where('plan_type', 'complex');
                    break;
                case 'proxy_dedicated':
                    $query->where('plan_type', 'simple')->where('type', 'dedicated');
                    break;
                case 'proxy_dedicated_turbo':
                    $query->where('plan_type', 'dedicated')->where('type', 'dedicated');
                    break;
                case 'proxy_shared':
                    $query->where('plan_type', 'simple')->where('type', '!=', 'dedicated');
                    break;
                case 'router':
                    $query->where('plan_type', 'router');
                    break;
                case 'vpn_dedicated':
                    $query->where('plan_type', 'vpn_dedicated');
                    break;
                case 'vpn':
                    $query->where('plan_type', 'vpn_dedicated')->whereIn('city', [5, 7]);
                    break;
            }
        }
        $isPlan = $request->get('filterRequestPlan') ? $request->get('filterRequestPlan') : null;
        session(['filterRequestPlan' => $isPlan]);
        if ($isPlan) {
            if ($isPlan == 'plans') $query->whereNull('parent_product');
                elseif ($isPlan == 'subplans') $query->whereNotNull('parent_product');
        }

        $category_id = $request->get('filterRequestCategory') ? $request->get('filterRequestCategory') : null;
        session(['filterRequestCategory' => $category_id]);
        if ($category_id) {
            $category = RecurlyCategories::with('parent.parent.parent.parent')->find($category_id);
            $cats[] = $category->id;
            foreach($category->getChildrenCategories as $subcat){
                $cats[] = $subcat->id;
                foreach($subcat->getChildrenCategories as $subcat1) {
                    $cats[] = $subcat1->id;
                    foreach($subcat1->getChildrenCategories as $subcat2) {
                        $cats[] = $subcat2->id;
                        foreach($subcat2->getChildrenCategories as $subcat3) {
                            $cats[] = $subcat3->id;
                        }
                    }
                }
            }
            $query->whereIn('category_id', $cats);
        }
        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('plan_code', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('plan_name', 'LIKE', '%'.$search['value'].'%');
            });
        }

        $recordsFiltered = $query->count();

        $orders = $request->get('order') ? $request->get('order') : [];
        session(['filter_order' => $orders]);
        $this->dataTableSorting($query, $columns, $orders);

        $length = $request->get('length') ? $request->get('length') : 10;
        $start = $request->get('start') ? $request->get('start') : 0;
        $draw = $request->get('draw') ? $request->get('draw') : 1;

        if ($length != -1) {
            $query->offset($start)->limit($length);
        }
        $collective = $query->get();

        $items = [];
        $plan_types = product_types_list();

        foreach($collective as $item) {
            if ($item->plan_availability == "in_stock") {
                $instock = '<span class="label label-sm label-success">Yes</span>';
            } else {
                $instock = '<span class="label label-sm label-danger">No</span>';
            }

            $plan_type = isset($plan_types[$item->getPlanType()]) ? $plan_types[$item->getPlanType()] : $item->plan_type;
            if ($item->plan_type == 'vpn_dedicated') {
                $plan_type .= '<br />' . $item->vpn_users . ' users';
            }
            if ($item->plan_type == 'vpn_dedicated' || $item->plan_type == 'router') $region = $item->city_name;
                else $region = $item->region;

            $category_tree = $item->categoryTree();
            if ($item->plan_type == 'simple' || $item->plan_type == 'dedicated') $category_tree .= '<br />' . $item->type;

            $items[] = [
                $item->id,
                $item->plan_code,
                $item->plan_name,
                $plan_type,
                $category_tree,
                $instock,
                ucfirst($item->billing_type),
                $item->getDurationText(),
                $item->plan_quantity,
                $item->unit_of_measure,
                $item->region_changeable ? 'Active' : '',
                $region,
                $item->price,
                '<a class="btn btn-default btn-sm" data-toggle="tooltip" title="Edit" href="'.action('Admin\ProductsController@getRecurlyProductUpdateForm', $item->id).'"><span class="glyphicon glyphicon-edit"></span></a>',
                //view('recurly.admin.products.recurly_product_delete_button', ['plan'=>$item])->render(),
                '<button onClick="delete_product($(this))" data-action="'.action('Admin\ProductsController@deleteRecurlyProductForm', $item->id).'" data-toggle="tooltip" title="Delete" class="btn btn-danger delete-product-button">Delete</button>',
            ];
        }
        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

    public function getRecurlyProductCreateFormSelect() {
        $plan_types = product_types_list();

        return view('recurly.admin.products.create.product_select_type', ['plan_types' => $plan_types]);
    }

    public function postRecurlyProductCreateFormSelect(Request $request) {
        $validator = $this->productTypeValidator($request->all());

        if ($validator->fails()) {
            $this->throwValidationException(
              $request, $validator
            );
        }

        return redirect()->action('Admin\ProductsController@getRecurlyProductCreateForm', $request->get('plan_type'));
    }

    /**
     * @return mixed
     */
    public function getRecurlyProductCreateForm(Request $request, $type) {
        $location           = Regions::getActivated();
        $cities             = City::activeList();
        $api_types          = recurly_plan_type($type);
        $rotation_period    = recurly_rotation_period();
        if ($request->old('billing_type') == 'trial') {
            if ($type == 'vpn') $plan_duration = recurly_plan_unlimit_duration();
                else $plan_duration = recurly_plan_trial_duration();
        } else {
            $plan_duration = recurly_plan_duration();
        }
        $plan_availability  = recurly_plan_availability();
        $plan_type          = plan_type();
        $unit_of_measure    = unit_of_measure();
        $billing_type       = billing_type();
        $plan_types = product_types_list();
        $category_tree = recurly_category_tree();
        $search_product_tree = '';
        if ($type == 'package') {
            $search_product_tree = recurly_search_product_tree(null, null, '1_months');
        } else {
            $search_product_tree = recurly_search_product_tree([1], [2]);
        }

        $data = [
            'plan_types' => $plan_types,
            'type' => $type,
            'location' => $location,
            'cities' => $cities,
            'api_types' => $api_types,
            'rotation_period' => $rotation_period,
            'plan_type' => $plan_type,
            'plan_availability' => $plan_availability,
            'unit_of_measure' => $unit_of_measure,
            'billing_type' => $billing_type,
            'plan_duration' => $plan_duration,
            'search_product_tree' => $search_product_tree,
            'category_tree' => $category_tree,
        ];

        $template = $this->getTemplateByType($type);
        if ($template) return view('recurly.admin.products.create.'.$template, $data);

        return view('recurly.admin.products.create_recurly_product', $data);
    }

    public function postCreateRecurlyProduct($type, Request $request) {
        $data = $request->all();
        $data['region_changeable'] = '';
        $data['rotation_period'] = 10;

        $this->prepareDataByPlanType($data, $type);

        if (isset($data['enable_discounts']) && !empty($data['enable_discounts'])) {
            $data['billing_type'] = 'duration';
            $data['duration'] = '1_months';
        }

        $validator = $this->productValidator($data);
        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $added_produsts = array();
        if ($data['recurly_create_plan_type'] == "complex" || $data['recurly_create_plan_type'] == "package") {
            if (isset($data['added_product_by_ajax']) && !empty($data['added_product_by_ajax'])) {
                $added_produsts_by_plan_code = array_filter($data['added_product_by_ajax']);
                $added_produsts = RecurlyProducts::whereIn('plan_code', $added_produsts_by_plan_code)->lists('id')->toArray();
            }
        } else if ($data['recurly_create_plan_type'] == 'dedicated') {
            $data['type'] = 'dedicated';
        }

        $planCategory = $data['recurly_category_id'];
        $childsOfCategory = RecurlyCategories::where('parent_category_id', $planCategory)->lists('id');
        if (!$childsOfCategory->isEmpty()) {
            return Redirect::back()->withErrors([
                'error' => 'Failed to create plan.you can not add product with parent category'
            ]);
        }

        if (!$data['region_changeable']) {
            if ($request->get('region_changeable')) $data['region_changeable'] = true;
                else $data['region_changeable'] = false;
        }

        try {
            if (isset($data['enable_discounts']) && !empty($data['enable_discounts'])) {
                $durations = ['01' => '1_months', '03' => '3_months', '06' => '6_months', '12' => '12_months'];
                $discount_setting = $this->settings->get('discounts_'.$data['recurly_category_id']);
                if ($discount_setting) {
                    $discount_setting = unserialize($discount_setting);
                }

                $parentPlan = null;
                foreach($durations as $key=>$duration) {
                    $localData = $data;
                    $localData['plan_code'] = $data['plan_code'] .'-'.$key;
                    $localData['duration'] = $duration;
                    $localData['price'] = $data['price'] * (int)$key;
                    if ($parentPlan) $localData['parent_product'] = $parentPlan->id;

                    if ($discount_setting) {
                        if (isset($discount_setting[(int)$key])) {
                            $localData['price'] = $localData['price'] - ($localData['price'] * $discount_setting[(int)$key] / 100);
                        }
                    }

                    $errors = [];
                    if ($data['billing_type'] == 'duration') {
                        $localData['price'] = round($localData['price'], 2);
                        $recurlyResponse = $this->recurlyService->createPlan($localData);
                        if ($recurlyResponse['success']) {
                            $createPlan = $recurlyResponse['plan'];
                        } else {
                            return redirect()->back()->withErrors([
                                'error' => 'Failed to create plan.Please fix these errors and try again :' . $recurlyResponse['error'],
                            ])->withInput();
                        }

                        $errors = $createPlan->getErrors();
                    }
                    if (empty($errors)) {
                        $localPlan = $this->createProduct($localData);
                        if ($key == '01') $parentPlan = $localPlan;
                        if ($localPlan) {
                            if ($localPlan->plan_type == 'complex' || $localPlan->plan_type == 'package') {
                                $localPlan->bundlePlans()->attach($added_produsts);
                            }
                            continue;
                        } else {
                            return Redirect::back()->withErrors([
                                'error' => 'Something wrong with local database insertion',
                            ]);
                        }
                    } else {
                        return redirect()->back()->withErrors([
                            'error' => 'Failed to create plan.',
                        ])->withInput();
                    }
                }
                return Redirect::back()->with('success', 'Plan successfully created.');
            } else {
                $errors = [];
                if ($data['billing_type'] == 'duration') {
                    $recurlyResponse = $this->recurlyService->createPlan($data);
                    if ($recurlyResponse['success']) {
                        $createPlan = $recurlyResponse['plan'];
                    } else {
                        return redirect()->back()->withErrors([
                            'error' => 'Failed to create plan. Please fix these errors and try again :' . $recurlyResponse['error'],
                        ])->withInput();
                    }

                    $errors = $createPlan->getErrors();
                }
                if (empty($errors)) {
                    $localPlan = $this->createProduct($data);
                    if ($localPlan) {
                        if ($localPlan->plan_type == 'complex' || $localPlan->plan_type == 'package') {
                            $localPlan->bundlePlans()->attach($added_produsts);
                        }

                        return Redirect::back()->with('success', 'Plan successfully created.');
                    } else {
                        return Redirect::back()->withErrors([
                            'error' => 'Something wrong with local database insertion',
                        ]);
                    }
                } else {
                    return redirect()->back()->withErrors([
                        'error' => 'Failed to create plan.',
                    ])->withInput();
                }
            }
        } catch (\Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message = $e->getMessage();
            } else {
                $error_message = $e;
            }
            return Redirect::back()->withErrors([
                'error' => 'Failed to create plan. Please fix these errors and try again :'.$error_message,
            ]);
        }
        catch (\Illuminate\Database\QueryException $e) {
            $this->recurlyService->deletePlanByCode($request->get('plan_code'));
            return Redirect::back()->withErrors([
                'error' => 'Something went wrong with local database insertion please contact with your administrator.'.$e->getMessage(),
            ]);
        }
    }

    public function getRecurlyProductUpdateForm(Request $request, RecurlyProducts $recurly_plan) {
        $errors = '';
        if ($recurly_plan->billing_type == 'duration') {
            $plan = $this->recurlyService->getPlanByCode($recurly_plan->plan_code);
            if (!$plan['success']) {
                $errors = $plan['error'];
            }
        }

        $isActivePlan = $recurly_plan->hasActivePlans();
        $subplans = $recurly_plan->subplans->count();

        $prev_query = RecurlyProducts::where('id', '<', $recurly_plan->id);
        $next_query = RecurlyProducts::where('id', '>', $recurly_plan->id);
        if ($type = session('filterRequestType')) {
            $prev_query->where('plan_type', $type);
            $next_query->where('plan_type', $type);
        }
        if ($isPlan = session('filterRequestPlan')) {
            if ($isPlan == 'plans') {
                $prev_query->whereNull('parent_product');
                $next_query->whereNull('parent_product');
            } elseif ($isPlan == 'subplans') {
                $prev_query->whereNotNull('parent_product');
                $next_query->whereNotNull('parent_product');
            }
        }
        if ($category_id = session('filterRequestCategory')) {
            $category = RecurlyCategories::find($category_id);
            $cats[] = $category->id;
            foreach($category->getChildrenCategories as $subcat){
                $cats[] = $subcat->id;
                foreach($subcat->getChildrenCategories as $subcat1) {
                    $cats[] = $subcat1->id;
                    foreach($subcat1->getChildrenCategories as $subcat2) {
                        $cats[] = $subcat2->id;
                        foreach($subcat2->getChildrenCategories as $subcat3) {
                            $cats[] = $subcat3->id;
                        }
                    }
                }
            }
            $prev_query->whereIn('category_id', $cats);
            $next_query->whereIn('category_id', $cats);
        }
        $prev_plan = $prev_query->orderby('id', 'desc')->first();
        $next_plan = $next_query->first();

        $location           = Regions::getActivated();
        $cities             = City::activeList();
        $api_types          = recurly_plan_type($recurly_plan->getPlanType());
        $rotation_period    = recurly_rotation_period();
        if ($recurly_plan->billing_type == 'trial') {
            if ($recurly_plan->isSimpleVPN()) $plan_duration = recurly_plan_unlimit_duration();
                else $plan_duration      = recurly_plan_trial_duration();
        } else {
            $plan_duration      = recurly_plan_duration();
        }

        $plan_availability  = recurly_plan_availability();
        $plan_type          = plan_type();
        $unit_of_measure    = unit_of_measure();
        $billing_type       = billing_type();
        $plan_types         = product_types_list();
        $category_tree      = recurly_category_tree();
        $search_product_tree = '';
        if ($recurly_plan->getPlanType() == 'package') {
            $search_product_tree = recurly_search_product_tree(null, null, '1_months');
        } else {
            $search_product_tree = recurly_search_product_tree([1], [2]);
        }

        $data = [
            'recurly_plan' => $recurly_plan,
            'prev_plan' => $prev_plan,
            'next_plan' => $next_plan,
            'subplans' => $subplans,
            'isActivePlan' => $isActivePlan,
            'location' => $location,
            'cities' => $cities,
            'api_types' => $api_types,
            'rotation_period' => $rotation_period,
            'plan_type' => $plan_type,
            'plan_availability' => $plan_availability,
            'unit_of_measure' => $unit_of_measure,
            'billing_type' => $billing_type,
            'plan_duration' => $plan_duration,
            'plan_types' => $plan_types,
            'category_tree' => $category_tree,
            'search_product_tree' => $search_product_tree,
            'recurlyError' => $errors
        ];
        if ($recurly_plan->plan_type == 'complex' || $recurly_plan->plan_type == 'package') {
            $data['bundle_plans'] = $recurly_plan->bundlePlans()->get();
        }

        $template = $this->getTemplateByPlan($recurly_plan);
        if ($template) return view('recurly.admin.products.update.'.$template, $data);

        return view('recurly.admin.products.update_recurly_simple_product', $data);
    }

    public function postRecurlyProductUpdateForm(Request $request, RecurlyProducts $recurly_plan){
        $subplans = $recurly_plan->subplans->count();
        $data = $request->all();

        $data['region_changeable'] = '';
        $data['rotation_period'] = 10;

        if ($recurly_plan->isSimpleVPN()) $plan_type = 'vpn';
            else $plan_type = $recurly_plan->getPlanType();
        $this->prepareDataByPlanType($data, $plan_type);

        if ($subplans > 0) $data['enable_discounts'] = 1;
        if ((isset($data['enable_discounts']) && !empty($data['enable_discounts'])) || $subplans > 0) {
            $data['billing_type'] = 'duration';
            $data['duration'] = '1_months';
        }

        $validator = $this->productValidator($data, $recurly_plan);

        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        if (!$recurly_plan->approveChanges($data)) {
            return Redirect::back()->withErrors([
                'error' => 'Plan Code, Plan Type, Plan Billing Type, Plan Category Cannot be edited unless subscribed by no customer',
            ]);
        }

        $added_produsts = array();
        if ($data['recurly_create_plan_type'] == 'complex' || $data['recurly_create_plan_type'] == 'package') {
            if (isset($data['added_product_by_ajax']) && !empty($data['added_product_by_ajax'])) {
                $added_produsts_by_plan_code = array_filter($data['added_product_by_ajax']);
                $added_produsts = RecurlyProducts::whereIn('plan_code', $added_produsts_by_plan_code)->lists('id')->toArray();
            }
        } else if ($data['recurly_create_plan_type'] == 'dedicated') {
            $data['type'] = 'dedicated';
        }

        $childsOfCategory = RecurlyCategories::where('parent_category_id', $data['recurly_category_id'])->lists('id');
        if (!$childsOfCategory->isEmpty()){
            return Redirect::back()->withErrors([
                'error' => 'Failed to create plan.you can not add product with parent category'
            ]);
        }

        if (!$data['region_changeable']) {
            if ($request->get('region_changeable')) $data['region_changeable'] = true;
                else $data['region_changeable'] = false;
        }

        try {
            if ((isset($data['enable_discounts']) && !empty($data['enable_discounts'])) || $subplans > 0) {
                if ($subplans > 0) {
                    $durations = ['1_months' => 1, '3_months' => 3, '6_months' => 6, '12_months' => 12];
                    $discount_setting = $this->settings->get('discounts_' . $data['recurly_category_id']);
                    if ($discount_setting) {
                        $discount_setting = unserialize($discount_setting);
                    }
                    $subplans = [];
                    $subplans[] = $recurly_plan;
                    foreach ($recurly_plan->subplans as $subplan) {
                        $subplans[] = $subplan;
                    }
                    foreach ($subplans as $key => $subplan) {
                        $localData = $data;
                        if ($key > 0) {
                            $localData['plan_code'] = $subplan->plan_code;
                            $localData['duration'] = $subplan->duration;
                        }
                        if (isset($durations[$subplan->duration])) {
                            $localData['price'] = $data['price'] * $durations[$subplan->duration];
                        }

                        if ($discount_setting) {
                            if (isset($discount_setting[$durations[$subplan->duration]])) {
                                $localData['price'] = $localData['price'] - ($localData['price'] * $discount_setting[$durations[$subplan->duration]] / 100);
                            }
                        }

                        if ($localData['billing_type'] == 'duration') {
                            $localData['price'] = round($localData['price'], 2);
                            $recurlyResponse = $this->recurlyService->updatePlan($subplan->plan_code, $localData);
                            if ($recurlyResponse['success']) {
                                $updatePlan = $recurlyResponse['plan'];
                            } else {
                                return redirect()->back()->withErrors([
                                    'error' => 'Failed to update plan. Please fix these errors and try again :' . $recurlyResponse['error'],
                                ])->withInput();
                            }
                            $errors = $updatePlan->getErrors();
                        } else {
                            $errors = [];
                        }

                        if (empty($errors) || $errors->count() == 0) {
                            $this->updateProduct($subplan, $localData);

                            if ($subplan) {
                                if ($data['recurly_create_plan_type'] == 'complex' || $data['recurly_create_plan_type'] == 'package') {
                                    $subplan->bundlePlans()->detach();
                                    $subplan->bundlePlans()->attach($added_produsts);
                                }
                                continue;
                            } else {
                                return Redirect::back()->withErrors([
                                    'error' => 'Something wrong with local database configration.Please contact with your administrator',
                                ]);
                            }
                        } else {
                            return redirect()->back()->withErrors([
                                'error' => 'Failed to create plan.',
                            ])->withInput();
                        }
                    }
                } else {
                    $durations = ['03' => '3_months', '06' => '6_months', '12' => '12_months'];
                    $discount_setting = $this->settings->get('discounts_'.$data['recurly_category_id']);
                    if ($discount_setting) {
                        $discount_setting = unserialize($discount_setting);
                    }

                    $parentPlan = null;
                    foreach($durations as $key=>$duration) {
                        $localData = $data;
                        $localData['plan_code'] = $data['plan_code'] .'-'.$key;
                        $localData['duration'] = $duration;
                        $localData['price'] = $data['price'] * (int)$key;
                        $localData['parent_product'] = $recurly_plan->id;

                        if ($discount_setting) {
                            if (isset($discount_setting[(int)$key])) {
                                $localData['price'] = $localData['price'] - ($localData['price'] * $discount_setting[(int)$key] / 100);
                            }
                        }

                        $errors = [];
                        if ($data['billing_type'] == 'duration') {
                            $localData['price'] = round($localData['price'], 2);
                            $recurlyResponse = $this->recurlyService->createPlan($localData);
                            if ($recurlyResponse['success']) {
                                $createPlan = $recurlyResponse['plan'];
                            } else {
                                return redirect()->back()->withErrors([
                                    'error' => 'Failed to create plan.Please fix these errors and try again :' . $recurlyResponse['error'],
                                ])->withInput();
                            }

                            $errors = $createPlan->getErrors();
                        }
                        if (empty($errors)) {
                            $localPlan = $this->createProduct($localData);
                            if ($localPlan) {
                                if ($localPlan->plan_type == 'complex' || $localPlan->plan_type == 'package') {
                                    $localPlan->bundlePlans()->attach($added_produsts);
                                }
                                continue;
                            } else {
                                return Redirect::back()->withErrors([
                                    'error' => 'Something wrong with local database insertion',
                                ]);
                            }
                        } else {
                            return redirect()->back()->withErrors([
                                'error' => 'Failed to create plan.',
                            ])->withInput();
                        }
                    }
                }
            }

            if ($data['billing_type'] == "duration") {
                $recurlyResponse = $this->recurlyService->updatePlan($recurly_plan->plan_code, $data);
                if ($recurlyResponse['success']) {
                    $updatePlan = $recurlyResponse['plan'];
                } else {
                    return redirect()->back()->withErrors([
                        'error' => 'Failed to update plan. Please fix these errors and try again :'.$recurlyResponse['error'],
                    ])->withInput();
                }

                $errors = $updatePlan->getErrors();
            } else {
                $errors = [];
            }

            if (empty($errors) || $errors->count() == 0) {
                $this->updateProduct($recurly_plan, $data);

                if ($recurly_plan) {
                    if ($data['recurly_create_plan_type'] == 'complex' || $data['recurly_create_plan_type'] == 'package') {
                        $recurly_plan->bundlePlans()->detach();
                        $recurly_plan->bundlePlans()->attach($added_produsts);
                    }
                    return Redirect::back()->with('success', 'Plan successfully updated.');
                } else {
                    return Redirect::back()->withErrors([
                        'error' => 'Something wrong with local database configration.Please contact with your administrator',
                    ]);
                }
            } else {
                return redirect()->back()->withErrors([
                    'error' => 'Failed to create plan.',
                ])->withInput();
            }
        }
        catch (\Recurly_ValidationError $e) {
            if ($e->getMessage()) {
                $error_message= $e->getMessage();
            } else {
                $error_message= $e;
            }
            return Redirect::back()->withErrors([
                'error' => 'Failed to create plan.Please fix these errors and try again :'.$error_message,
            ]);
        }
        catch (Recurly_NotFoundError $e) {
            if ($e->getMessage()) {
                $error_message= $e->getMessage();
            } else {
                $error_message= $e;
            }
            return Redirect::back()->withErrors([
                'error' => 'Plan not found! :'.$error_message,
            ]);
        }
        catch (\Illuminate\Database\QueryException $e) {
            //$this->recurlyService->deletePlanByCode($request->get('plan_code'));
            return Redirect::back()->withErrors([
                'error' => 'something went wrong with local database insertion please contact with your administrator.',
            ]);
        }
    }

    public function getRecurlyProductCopyForm(Request $request, RecurlyProducts $recurly_plan) {
        $isActivePlan = $recurly_plan->hasActivePlans();
        $subplans = $recurly_plan->subplans->count();

        if ($recurly_plan->isSimpleVPN()) $plan_type = 'vpn';
            else $plan_type = $recurly_plan->getPlanType();

        $location           = Regions::getActivated();
        $cities             = City::activeList();
        $api_types          = recurly_plan_type($recurly_plan->getPlanType());
        $rotation_period    = recurly_rotation_period();
        if ($recurly_plan->billing_type == 'trial') {
            if ($recurly_plan->isSimpleVPN()) $plan_duration = recurly_plan_unlimit_duration();
                else $plan_duration = recurly_plan_trial_duration();
        } else {
            $plan_duration      = recurly_plan_duration();
        }
        $plan_availability  = recurly_plan_availability();
        $unit_of_measure    = unit_of_measure();
        $billing_type       = billing_type();
        $plan_types         = product_types_list();
        $category_tree      = recurly_category_tree();
        $search_product_tree = '';
        if ($recurly_plan->getPlanType() == 'package') {
            $search_product_tree = recurly_search_product_tree(null, null, '1_months');
        } else {
            $search_product_tree = recurly_search_product_tree([1], [2]);
        }

        $data = [
          'recurly_plan' => $recurly_plan,
          'plan_type' => $plan_type,
          'subplans' => $subplans,
          'isActivePlan' => $isActivePlan,
          'location' => $location,
          'cities' => $cities,
          'api_types' => $api_types,
          'rotation_period' => $rotation_period,
          'plan_availability' => $plan_availability,
          'unit_of_measure' => $unit_of_measure,
          'billing_type' => $billing_type,
          'plan_duration' => $plan_duration,
          'plan_types' => $plan_types,
          'category_tree' => $category_tree,
          'search_product_tree' => $search_product_tree,
        ];
        if ($recurly_plan->plan_type == 'complex' || $recurly_plan->plan_type == 'package') {
            $data['bundle_plans'] = $recurly_plan->bundlePlans()->get();
        }

        $template = $this->getTemplateByPlan($recurly_plan);
        if ($template) return view('recurly.admin.products.copy.'.$template, $data);

        return view('recurly.admin.products.copy_recurly_simple_product', $data);
    }

    public function deleteRecurlyProductForm($plan_id, Request $request) {
        $subscription_count = CustomerProxyData::where('plan_id',$plan_id)->where('enabled',1)->count();
        if($subscription_count > 0) {
            return Redirect::back()->withErrors([
                'error' => 'Product Cannot Be Deleted. It Has Been Subscribed By Customers.',
            ]);
        }

        $subscription_count = CustomerVPNData::where('plan_id',$plan_id)->where('enabled',1)->count();
        if($subscription_count > 0) {
            return Redirect::back()->withErrors([
                'error' => 'Product Cannot Be Deleted. It Has Been Subscribed By Customers.',
            ]);
        }

        $bundle_plan_count = DB::table('bundle_plans')->where('plan_id', $plan_id)->count();
        if($bundle_plan_count > 0) {
            return Redirect::back()->withErrors([
                'error' => 'Product Cannot Be Deleted. Its Part Of A Complex Product.',
            ]);
        }

        $recurly_product = RecurlyProducts::findorfail($plan_id);
        $plan_code = $recurly_product->plan_code;
        $purchased_count = PurchasePlans::where('plan_id', $recurly_product->id)->count();
        if ($purchased_count > 0) {
            return Redirect::back()->withErrors([
                'error' => 'Product Cannot Be Deleted. One or more purchased services assigned to this plan.',
            ]);
        }

        try {
            $this->recurlyService->deletePlanByCode($plan_code);
            $response = $this->recurlyService->getPlanByCode($plan_code);
            if (!$response['success']) {
                DB::table('bundle_plans')->where('bundle_id', $plan_id)->delete();
                $recurly_product->delete();
                return Redirect::back()->with('success', 'Product Successfully Deleted.');
            }
            return Redirect::back()->withErrors([
                'error' => 'Product was not deleted.',
            ]);

        } catch (Recurly_NotFoundError $e) {
            return Redirect::back()->withErrors([
                'error' => $e,
            ]);
        }

    }

    public function updatePrices(RecurlyCategories $category) {
        $count = 0;
        $products = RecurlyProducts::has('subplans')->where('category_id', $category->id)->whereNull('parent_product')->get();
        foreach($products as $product) {
            $price = $product->price;
            try {
                $durations = ['1_months' => 1, '3_months' => 3, '6_months' => 6, '12_months' => 12];
                $discount_setting = $this->settings->get('discounts_'.$category->id);
                if ($discount_setting) {
                    $discount_setting = unserialize($discount_setting);
                }
                $subplans = [];
                foreach($product->subplans as $subplan) {
                    $subplans[] = $subplan;
                }
                foreach($subplans as $key=>$subplan) {
                    if (isset($durations[$subplan->duration])) {
                        $new_price = $price * $durations[$subplan->duration];
                    }

                    if ($discount_setting) {
                        if (isset($discount_setting[$durations[$subplan->duration]])) {
                            $new_price = $new_price - ($new_price * $discount_setting[$durations[$subplan->duration]] / 100);
                        }
                    }

                    if ($subplan->billing_type == 'duration') {
                        $data = [
                            'plan_code' => $subplan->plan_code,
                            'plan_name' => $subplan->plan_name,
                            'plan_description' => $subplan->plan_description,
                            'price' => round($new_price, 2),
                            'billing_type' => $subplan->billing_type,
                            'duration' => $subplan->duration,
                        ];
                        $recurlyResponse = $this->recurlyService->updatePlan($subplan->plan_code, $data);
                        if ($recurlyResponse['success']) {
                            $updatePlan = $recurlyResponse['plan'];
                        } else {
                            return redirect()->back()->withErrors([
                                'error' => 'Failed to update plan. Please fix these errors and try again : ' . $recurlyResponse['error'],
                            ])->withInput();
                        }
                        $errors = $updatePlan->getErrors();
                    } else {
                        $errors = [];
                    }

                    if (empty($errors) || $errors->count() == 0) {
                        $subplan->price = $new_price;
                        $subplan->save();
                        if ($subplan) {
                            $count++;
                            continue;
                        } else {
                            return Redirect::back()->withErrors([
                                'error' => 'Something wrong with local database configration.Please contact with your administrator',
                            ]);
                        }
                    } else {
                        return redirect()->back()->withErrors([
                            'error' => 'Failed to create plan.',
                        ])->withInput();
                    }
                }
            }
            catch (\Recurly_ValidationError $e) {
                if ($e->getMessage()) {
                    $error_message= $e->getMessage();
                } else {
                    $error_message= $e;
                }
                return Redirect::back()->withErrors([
                    'error' => 'Failed to create plan.Please fix these errors and try again :'.$error_message,
                ]);
            }
            catch (Recurly_NotFoundError $e) {
                if ($e->getMessage()) {
                    $error_message= $e->getMessage();
                } else {
                    $error_message= $e;
                }
                return Redirect::back()->withErrors([
                    'error' => 'Plan not found! :'.$error_message,
                ]);
            }
            catch (\Illuminate\Database\QueryException $e) {
                return Redirect::back()->withErrors([
                    'error' => 'something went wrong with local database insertion please contact with your administrator.',
                ]);
            }
        }
        if ($count > 0) {
            return Redirect::back()->with('success', 'Plans successfully updated.');
        } else {
            return Redirect::back()->withErrors([
                'error' => 'No products to update',
            ]);
        }
    }

    protected function createProduct(array $data) {
        if ($data['billing_type'] == 'duration') {
            $plan_price = $this->recurlyService->getPlanPrice($data['price'], $data['billing_type']);
        } else {
            $plan_price = 0;
        }

        if ($data['recurly_create_plan_type'] != 'vpn_dedicated' && $data['type'] != 'router_vpn') {
            unset($data['vpn_users']);
            unset($data['city']);
        }

        if ($data['recurly_create_plan_type'] == 'vpn_dedicated') $data['plan_quantity'] = 1;

        $data['anytime_ports'] = $data['plan_quantity'];
        if ($data['type'] == 'dedicated' || $data['type'] == 'dedicated_turbospin') {
            $data['anytime_threads'] = 150;
        } else {
            $data['anytime_threads'] = 50;
        }

        if ($data['recurly_create_plan_type'] == 'vpn_dedicated' || $data['recurly_create_plan_type'] == 'dedicated') $data['type'] = 'dedicated';

        $localPlan = RecurlyProducts::create([
            'plan_code' =>  isset($data['plan_code']) ? $data['plan_code'] : null,
            'plan_name' => isset($data['plan_name']) ? $data['plan_name'] : null,
            'plan_description' => isset($data['plan_description']) ? $data['plan_description'] : null,
            'price' => $plan_price,
            'setup_fee' => isset($data['setup_fee']) ? $data['setup_fee'] : null,
            'category_id' => isset($data['recurly_category_id']) ? $data['recurly_category_id'] : null,
            'type' => isset($data['type']) ? $data['type'] : null,
            'switch_type' => isset($data['switch_type']) ? $data['switch_type'] : null,
            'rotation_period' => isset($data['rotation_period']) ? $data['rotation_period'] : null,
            'location' => isset($data['location']) ? $data['location'] : null,
            'city' => isset($data['city']) ? $data['city'] : null,
            'region_changeable' => isset($data['region_changeable']) ? $data['region_changeable'] : null,
            'plan_type' =>  isset($data['recurly_create_plan_type']) ? $data['recurly_create_plan_type'] : null,
            'plan_availability' => isset($data['plan_availability']) ? $data['plan_availability'] : null,
            'plan_quantity' => isset($data['plan_quantity']) ? $data['plan_quantity'] : null,
            'unit_of_measure' => isset($data['unit_of_measure']) ? $data['unit_of_measure'] : null,
            'billing_type' => isset($data['billing_type']) ? $data['billing_type'] : null,
            'duration' => isset($data['duration']) ? $data['duration'] : null,
            'anytime_ports' => isset($data['anytime_ports']) ? $data['anytime_ports'] : null,
            'anytime_threads' => isset($data['anytime_threads']) ? $data['anytime_threads'] : null,
            'router_model' => isset($data['router_model']) ? $data['router_model'] : null,
            'vpn_users' => isset($data['vpn_users']) ? $data['vpn_users'] : null,
            'parent_product' => isset($data['parent_product']) ? $data['parent_product'] : null,
        ]);
        return $localPlan;
    }

    protected function updateProduct(RecurlyProducts $localPlan, array $data) {
        if ($data['billing_type'] == 'duration') {
            $plan_price = $this->recurlyService->getPlanPrice($data['price'], $data['billing_type']);
        } else {
            $plan_price = 0;
        }

        if ($data['recurly_create_plan_type'] != 'vpn_dedicated' && $data['type'] != 'router_vpn') {
            unset($data['vpn_users']);
            unset($data['city']);
        }

        if ($data['recurly_create_plan_type'] == 'vpn_dedicated') $data['plan_quantity'] = 1;

        $data['anytime_ports'] = $data['plan_quantity'];
        if ($data['type'] == 'dedicated' || $data['type'] == 'dedicated_turbospin') {
            $data['anytime_threads'] = 150;
        } else {
            $data['anytime_threads'] = 50;
        }

        if ($data['recurly_create_plan_type'] == 'vpn_dedicated' || $data['recurly_create_plan_type'] == 'dedicated') $data['type'] = 'dedicated';

        $localPlan->plan_code = isset($data['plan_code']) ? $data['plan_code'] : null;
        $localPlan->plan_name = isset($data['plan_name']) ? $data['plan_name'] : null;
        $localPlan->plan_description = isset($data['plan_description']) ? $data['plan_description'] : null;
        $localPlan->price = $plan_price;
        $localPlan->setup_fee = isset($data['setup_fee']) ? $data['setup_fee'] : null;
        $localPlan->category_id = isset($data['recurly_category_id']) ? $data['recurly_category_id'] : null;
        $localPlan->type = isset($data['type']) ? $data['type'] : null;
        $localPlan->switch_type = isset($data['switch_type']) ? $data['switch_type'] : null;
        $localPlan->rotation_period = isset($data['rotation_period']) ? $data['rotation_period'] : null;
        $localPlan->location = isset($data['location']) ? $data['location'] : null;
        $localPlan->city = isset($data['city']) ? $data['city'] : null;
        $localPlan->region_changeable = isset($data['region_changeable']) ? $data['region_changeable'] : null;
        $localPlan->plan_type = isset($data['recurly_create_plan_type']) ? $data['recurly_create_plan_type'] : null;
        $localPlan->plan_availability = isset($data['plan_availability']) ? $data['plan_availability'] : null;
        $localPlan->plan_quantity = isset($data['plan_quantity']) ? $data['plan_quantity'] : null;
        $localPlan->unit_of_measure = isset($data['unit_of_measure']) ? $data['unit_of_measure'] : null;
        $localPlan->billing_type = isset($data['billing_type']) ? $data['billing_type'] : null;
        $localPlan->duration = isset($data['duration']) ? $data['duration'] : null;
        $localPlan->anytime_ports = isset($data['anytime_ports']) ? $data['anytime_ports'] : null;
        $localPlan->anytime_threads = isset($data['anytime_threads']) ? $data['anytime_threads'] : null;
        $localPlan->router_model = isset($data['router_model']) ? $data['router_model'] : null;
        $localPlan->day_requests = isset($data['day_requests']) ? $data['day_requests'] : null;
        $localPlan->month_requests = isset($data['month_requests']) ? $data['month_requests'] : null;
        $localPlan->vpn_users = isset($data['vpn_users']) ? $data['vpn_users'] : null;
        $localPlan->save();

        return $localPlan;
    }

    /**
     * Create/Edit recurly product validator
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function productValidator(array $data, RecurlyProducts $recurlyProduct = null) {
        $rules = [
            'plan_code'                 => 'required|string|max:255|unique:recurly_products,plan_code',
            'plan_name'                 => 'required|string|max:255',
            'plan_description'          => 'required|string|max:255',
            'price'                     => 'numeric',
            'recurly_category_id'       => 'required|integer',
            'recurly_create_plan_type'  => 'required|in:' . implode(',', array_keys(plan_type())),
            'rotation_period'           => 'integer',
            'type'                      => 'required|in:' . implode(',', array_keys(recurly_plan_type())),
            'switch_type'               => 'in:' . implode(',', array_keys(recurly_plan_switch_type())),
            'location'                  => 'exists:regions,rid,active,1',
            'city'                      => 'exists:cities,cid,active,1',
            'region_changeable'         => 'boolean',
            'plan_availability'         => 'required|in:' . implode(',', array_keys(recurly_plan_availability())),
            'plan_quantity'             => 'required|integer',
            'unit_of_measure'           => 'required|in:' . implode(',', array_keys(unit_of_measure())),
            'billing_type'              => 'required|in:' . implode(',', array_keys(billing_type())),
            'duration'                  => 'required|in:',
            'day_requests'              => 'integer',
            'month_requests'            => 'integer',
        ];
        if ($data['billing_type'] == 'trial') {
            if ($data['recurly_create_plan_type'] == 'vpn_dedicated' && $data['city'] == 5) {
                $rules['duration'] .= implode(',', array_keys(recurly_plan_unlimit_duration()));
            } else $rules['duration'] .= implode(',', array_keys(recurly_plan_trial_duration()));
        } else $rules['duration'] .= implode(',', array_keys(recurly_plan_duration()));
        if ($data['recurly_create_plan_type'] == 'vpn_dedicated') {
            unset($rules['type']);
            $rules['vpn_users'] = 'required|integer';
            $data['plan_quantity'] = 1;
            $rules['city'] .= '|required';
        }

        if ($data['recurly_create_plan_type'] == 'router') {
            $rules['type'] = 'required|in:router_only,router_vpn';
            $rules['setup_fee'] = 'numeric';
            $rules['router_model'] = 'required|string|max:255';
            if ($data['type'] == 'router_vpn') {
                $rules['vpn_users'] = 'required|integer';
                $rules['city'] .= '|required';
            }
        }

        $messages = [
            'recurly_category_id.required' => 'Please select a category'
        ];
        if ($recurlyProduct) $rules['plan_code'] .= ','.$recurlyProduct->id;
        return Validator::make($data, $rules, $messages);
    }

    /**
     * Select product type validator
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function productTypeValidator(array $data) {
        $rules = [
            'plan_type' => 'required|in:'.implode(',', array_keys(product_types_list())),
        ];
        return Validator::make($data, $rules);
    }

    protected function getTemplateByType($type) {
        $template = '';
        switch ($type) {
            case 'proxy_complex':
                $template = 'proxy_complex';
                break;
            case 'proxy_shared':
                $template = 'proxy_shared';
                break;
            case 'proxy_dedicated':
                $template = 'proxy_dedicated';
                break;
            case 'proxy_dedicated_turbo':
                $template = 'proxy_dedicated_turbo';
                break;
            case 'vpn_dedicated':
                $template = 'vpn_dedicated';
                break;
            case 'vpn':
                $template = 'vpn';
                break;
            case 'router':
                $template = 'router';
                break;
            case 'package':
                $template = 'package';
                break;
            default:
                $template = '';
                break;
        }

        return $template;
    }

    protected function getTemplateByPlan(RecurlyProducts $plan) {
        $plan_type = $plan->getPlanType();
        $template = '';
        if ($plan_type == 'proxy_complex') $template = 'proxy_complex';
            else if ($plan_type == 'proxy_shared') $template = 'proxy_shared';
            else if ($plan_type == 'proxy_dedicated') $template = 'proxy_dedicated';
            else if ($plan_type == 'proxy_dedicated_turbo') $template = 'proxy_dedicated_turbo';
            else if ($plan_type == 'vpn_dedicated') {
                if ($plan->getCityId() == 5 || $plan->getCityId() == 7) $template = 'vpn';
                    else $template = 'vpn_dedicated';
            }
            else if ($plan_type == 'router') $template = 'router';
            else if ($plan_type == 'package') $template = 'package';
        return $template;
    }

    protected function prepareDataByPlanType(&$data, $type) {
        switch ($type) {
            case 'proxy_complex':
                $data['recurly_create_plan_type'] = 'complex';
                $data['type'] = 'shared';
                $data['region_changeable'] = TRUE;
                $data['location'] = 11;
                break;
            case 'proxy_shared':
                $data['recurly_create_plan_type'] = 'simple';
                $data['location'] = 11;
                break;
            case 'proxy_dedicated':
                $data['recurly_create_plan_type'] = 'simple';
                $data['type'] = 'dedicated';
                $data['switch_type'] = 'basic';
                break;
            case 'proxy_dedicated_turbo':
                $data['recurly_create_plan_type'] = 'dedicated';
                $data['type'] = 'dedicated';
                $data['switch_type'] = 'turbo';
                break;
            case 'vpn_dedicated':
                $data['recurly_create_plan_type'] = 'vpn_dedicated';
                $data['type'] = 'dedicated';
                break;
            case 'vpn':
                $data['recurly_create_plan_type'] = 'vpn_dedicated';
                $data['type'] = 'dedicated';
                if ($data['billing_type'] == 'trial') {
                    $data['city'] = 5;
                    $data['vpn_users'] = 1;
                    $data['duration'] = -1;
                } else $data['city'] = 7;
                break;
            case 'router':
                $data['recurly_create_plan_type'] = 'router';
                $data['type'] = 'router_only';
                break;
            case 'package':
                $data['recurly_create_plan_type'] = 'package';
                $data['type'] = 'shared';
                $data['billing_type'] = 'duration';
                $data['duration'] = '1_months';
                break;
        }
    }
	
}
