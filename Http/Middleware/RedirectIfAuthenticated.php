<?php

namespace App\Http\Middleware;

use App\Models\RecurlyProducts;
use Closure;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  $guard
     * @return mixed
     */
    public function handle($request, Closure $next, $guard = null)
    {
        if (Auth::guard($guard)->check()) {
            if ($request->has('plan_code')) {
                $selected_plan = RecurlyProducts::where('plan_code', $request->get('plan_code'))->where('billing_type', '!=', 'trial')->first();
                if ($selected_plan) {
                    if ($selected_plan->plan_type == 'vpn_dedicated') {
                        return redirect()->action('RecurlyController@recurlyProductsNew', [
                          'type' => 'vpn',
                          'plan_code' => $selected_plan->plan_code
                        ]);
                    } else if ($selected_plan->plan_type == 'router') {
                        return redirect()->action('RecurlyController@recurlyProductsNew', [
                          'type' => 'router',
                          'plan_code' => $selected_plan->plan_code
                        ]);
                    } else if ($selected_plan->plan_type == 'package') {
                        $category = $selected_plan->category; $type = 'router';
                        $parent_cat = $category->mainParent();
                        switch ($parent_cat->id) {
                            case 1:
                                $type = 'proxy';
                                break;
                            case 42:
                                $type = 'vpn';
                                break;
                            case 78:
                                $type = 'router';
                                break;
                        }
                        return redirect()->action('RecurlyController@recurlyProductsNew', ['type'=>$type, 'plan_code'=>$selected_plan->plan_code]);
                    } else {
                        return redirect()->action('RecurlyController@recurlyProductsNew', ['type'=>'proxy', 'plan_code'=>$selected_plan->plan_code]);
                    }
                }
                return redirect('/');
            } else {
                return redirect('/');
            }
        }

        return $next($request);
    }
}
