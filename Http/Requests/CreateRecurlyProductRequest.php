<?php

namespace App\Http\Requests;

use App\Http\Requests\Request;

class CreateRecurlyProductRequest extends Request
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
	        'plan_code'                 => 'required|string|unique:recurly_products,plan_code'.$this->get('id'),
	        'plan_name'                 => 'required|string',
	        'plan_description'          => 'required|string',
	        'price'                     => 'numeric',
	        'recurly_category_id'       => 'required|integer',
	        'rotation_period'           => 'integer',
	        'type'                      => 'required|string',
            'location'                  => 'string',
            'region_changeable'         =>  'boolean',

            'plan_availability'         => 'required|string',
	        'plan_quantity'             => 'required|integer',
	        'unit_of_measure'           => 'required|string',
	        'billing_type'              => 'required|string',
	        'duration'                  => 'required|string',
	        'anytime_ports'             => 'required|integer',
//            'day_ports'                 => 'required|integer',
//	        'month_ports'               => 'required|integer',
	        'anytime_threads'           => 'required|integer',
//	        'day_threads'               => 'required|integer',
//	        'month_threads'             => 'required|integer',
//	        'anytime_requests'          => 'required|integer',
	        'day_requests'              => 'integer',
	        'month_requests'            => 'integer',
        ];
    }
}
