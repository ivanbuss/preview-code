<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use App\Models\CustomerVPNData;
use App\Models\ErrorLog;
use App\Models\PurchasePlans;
use App\Models\RecurlyProducts;
use App\Services\RecurlyService;
use App\Services\Settings;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon;
use Illuminate\Support\Facades\Mail;

class HomeController extends Controller
{

    protected $recurlyService;
    protected $settings;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(RecurlyService $recurlyService, Settings $settings)
    {
        $this->recurlyService = $recurlyService;
        $this->settings = $settings;
        $this->middleware('auth');
    }

    /**
     * Show the application home page.
     *
     * @return \Illuminate\Http\Response
     */
    public function getIndex()
    {
        return redirect()->route('dashboard');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function getDashboard(Request $request) {
        if ($request->user()->role == 'admin') {
            return $this->showAdminDashboard();
        } else {
            return $this->showDashboard($request->user());
        }
    }

    protected function showAdminDashboard() {
        return view('admin_dashboard');
    }

    protected function showDashboard(User $user) {
        $show_box = false; $current_time = Carbon::now(); $purchase_plan = null;
        $expiry_time = null; $diff_time_string = null;
        return view('user_dashboard', [
            'expiry' => $expiry_time ? $expiry_time : null,
            'diff_time' => $diff_time_string ? $diff_time_string : null,
            'show_box' => $show_box,
            'purchase_plan' => $purchase_plan,
            'current_time' => $current_time,
            'user'=>$user,
        ]);
    }
}
