<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\PsPayment;
use App\Services\Settings;
use Illuminate\Http\Request;

use App\Http\Requests;
use Illuminate\Support\Facades\Validator;

class PaymentsController extends Controller
{

    function __construct() {
    }

    public function getPayments(Request $request) {
        return view('recurly.admin.payments.list');
    }

    public function getPaymentsTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'user_id'],
            2 => ['name'=>'method'],
            3 => ['name'=>'payment_id'],
            4 => ['name'=>'state'],
            5 => ['name'=>'amount'],
            6 => ['name'=>'create_time'],
        ];

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = PsPayment::select('payments.*');

        $recordsTotal = $query->count();

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('payment_id', 'LIKE', '%'.$search['value'].'%');
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
                $item->user_id,
                $item->method,
                $item->payment_id,
                $item->state,
                $item->amount .' '. $item->currency,
                $item->create_time->format('F jS Y g:i A'),
            ];
        }

        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

}
