<?php

namespace App\Http\Controllers\Admin;

use App\Models\ErrorLog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

use App\Http\Requests;
use Carbon\Carbon;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;


class ErrorLogController extends Controller
{

    function __construct() {}

    public function getLog() {
        return view('recurly.admin.errors.log');
    }

    public function logTableData(Request $request) {
        $columns = [
            0 => ['name'=>'id'],
            1 => ['name'=>'path'],
            2 => ['name'=>'code'],
            3 => ['name'=>'file'],
            4 => ['name'=>'line'],
            5 => ['name'=>'message'],
            6 => ['name'=>'created_at']
        ];

        $count = 0;
        $orders = $request->get('order') ? $request->get('order') : [];

        $query = ErrorLog::select('errors_log.*');

        $recordsTotal = $query->count();

        $search = $request->get('search') ? $request->get('search') : [];
        if ($search['value']) {
            $query->where(function($subquery) use ($search) {
                $subquery->where('path', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('code', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('file', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('line', 'LIKE', '%'.$search['value'].'%')
                    ->orWhere('message', 'LIKE', '%'.$search['value'].'%');
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
                $item->path,
                $item->code,
                $item->file,
                $item->line,
                $item->message,
                $item->created_at->format('F jS Y g:i A'),
                view('recurly.admin.errors.edit_button', ['item'=>$item])->render(),
                '<button onClick="delete_error($(this))" data-action="'.action('Admin\ErrorLogController@delete', $item->id).'" data-toggle="tooltip" title="Delete" class="btn btn-danger delete-product-button">Delete</button>',
            ];
        }

        return json_encode(['draw'=>$draw, 'recordsTotal'=>$recordsTotal, 'recordsFiltered'=>$recordsFiltered, 'data'=>$items]);
    }

    public function getShow(ErrorLog $item, Request $request) {
        return view('recurly.admin.errors.view', ['item'=>$item]);
    }

    public function delete(ErrorLog $item, Request $request) {
        $item->delete();

        return redirect()->action('Admin\ErrorLogController@getLog')->with('success', 'Error has been deleted.');
    }

}
