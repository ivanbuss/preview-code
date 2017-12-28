<?php

namespace App\Http\Requests;

use App\Http\Requests\Request;

class CreateCategoryRequest extends Request
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
	    $parent_category_id =Request::get('parent_category_id');
	    return [
            'name' => 'required|string|unique:recurly_categories,name,NULL,id,parent_category_id,'.$parent_category_id.'',
            'description' => 'string',
        ];
    }
}
