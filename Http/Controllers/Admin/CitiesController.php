<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Services\Settings;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Validator;

class CitiesController extends Controller
{

	protected $settings;

    function __construct(Settings $settings) {
		$this->settings = $settings;
    }

    public function getList(Request $request) {
        return view('recurly.admin.cities.list');
    }

    public function getListTableData(Request $request) {
        $columns = [
            0 => ['name'=>'cid'],
            1 => ['name'=>'name'],
            2 => ['name'=>'active'],
        ];

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = City::select('cities.*');

        $recordsTotal = $query->count();

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('cid', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('name', 'LIKE', '%'.$search['value'].'%');
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
                $item->cid,
                $item->name,
                $item->active ? 'Active' : 'Disabled',
                view('recurly.admin.cities.edit_button', ['city'=>$item])->render(),
                '<button onClick="delete_city($(this))" data-action="'.action('Admin\CitiesController@delete', $item->id).'" data-toggle="tooltip" title="Delete" class="btn btn-danger delete-product-button">Delete</button>',
            ];
        }

        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

    public function getCreate(Request $request) {
        return view('recurly.admin.cities.create');
    }

    public function postCreate(Request $request) {
        $validator = $this->validator($request->all());

        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $city = $this->create($request->get('cid'), $request->get('name'), $request->get('active'));
        if ($city) return redirect()->action('Admin\CitiesController@getList')->with('success', 'New City has been created.');
            else return redirect()->back()->withInput()->with('error', 'Error occurred');
    }

    public function getEdit(City $city, Request $request) {
        return view('recurly.admin.cities.edit', ['city'=>$city]);
    }

    public function postUpdate(City $city, Request $request) {
        $validator = $this->validator($request->all(), $city);

        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

        $city->cid = $request->get('cid');
        $city->name = $request->get('name');
        $city->active = $request->get('active') ? TRUE : FALSE;
        $city->save();

        return redirect()->action('Admin\CitiesController@getList')->with('success', 'City has been updated.');
    }

    public function delete(City $city, Request $request) {
        $city->delete();

        return redirect()->action('Admin\CitiesController@getList')->with('success', 'City has been deleted.');
    }

    protected function create($cid, $name, $status) {
        $city = City::create([
            'cid' => $cid,
            'name' => $name,
            'active' => $status ? true : false,
        ]);
        return $city;
    }

    public function validator(array $data, City $city = null) {
        $rules = [
            'cid' => 'required|numeric|unique:cities,cid',
            'name' => 'required|string',
            'active' => 'boolean'
        ];
        if ($city) $rules['cid'] .= ','.$city->id;
        return Validator::make($data, $rules);
    }

}
