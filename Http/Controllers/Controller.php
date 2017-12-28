<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected function dataTableSorting(&$query, $colums, $orders) {
        foreach($orders as $order) {
            if (isset($colums[$order['column']])) {
                $query->orderBy($colums[$order['column']]['name'], $order['dir']);
            }
        }
    }
}
