<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class RecurlyCategories extends Model
{
	protected $table = 'recurly_categories';
	protected $primaryKey='id';
	protected $fillable = [
		'id','name','description','level_name','parent_category_id','cat_level_id', 'weight',
		'trial', 'is_default', 'no_subcats', 'recommended_plan', 'created_at','updated_at'
	];

	public static function getList()
	{
		$options = static::lists('name','id')->all();
		$select = array('' => 'Select Product category');
		$output =$select+$options;
		return $output;
	}
	public static function getTopParentList()  //top parent
	{
		$options = static::where('parent_category_id',0)->where('trial', 0)->lists('name','id')->all();
		$select = array('' => 'Category');
		$output =$select+$options;
		return $output;
	}

	public function parent() {
		return $this->belongsTo('App\Models\RecurlyCategories', 'parent_category_id', 'id');
	}

	public function getChildrenCategories()
    {
        return $this->hasMany('App\Models\RecurlyCategories', 'parent_category_id')->orderBy('weight', 'ASC');
    }

	public function scopeParentCategories($query)
	{
		return $query->where('parent_category_id',0);
	}

	public function children_categories()
    {
        return $this->hasMany('App\Models\RecurlyCategories', 'parent_category_id');
	}

	public function products()
	{
		return $this->hasMany('App\Models\RecurlyProducts', 'category_id');
	}

	public function recPlan() {
		return $this->hasOne('App\Models\RecurlyProducts', 'recommended_plan');
	}

	public function firstMenuChildrenCategories()
	{
		return $this->hasMany('App\Models\RecurlyCategories', 'parent_category_id')
			->has('userProducts')
			->orWhereHas('children_categories',function($query){
			$query->has('userProducts');
		});
	}

	public function secondMenuChildrenCategories()
	{
		return $this->hasMany('App\Models\RecurlyCategories', 'parent_category_id')->has('userProducts');
	}

	public function firstMenuChildrenProducts()
	{
		return $this->hasMany('App\Models\RecurlyCategories', 'parent_category_id')->has('userProducts');
	}

	public function userProducts(){
		$relation =  $this->hasMany('App\Models\PurchasePlans','category_id');
		if(Auth::check()){
			$relation = $relation->where('customer_id', Auth::user()->id);
		}
		return $relation;
	}

    public function getChildrenList() {
        $cats = $this->getChildrenRecursive();

        return $cats;
    }

	protected function getChildrenRecursive() {
		$cats = [];
		foreach($this->children_categories as $cat) {
			$cats[] = $cat->id;
			$cats = array_merge($cats, $cat->getChildrenRecursive());
		}
		return $cats;
	}

	public function categoryTree() {
		$list = '';
		$list = $this->name . $list;
		if ($this->parent) {
			$list = $this->parent->name . ' > ' .$list;
			if ($this->parent->parent) {
				$list = $this->parent->parent->name . ' > ' .$list;
				if ($this->parent->parent->parent) {
					$list = $this->parent->parent->parent->name . ' > ' .$list;
					if ($this->parent->parent->parent->parent) $list = $this->parent->parent->parent->parent->name . ' > ' .$list;
				}
			}
		}
        return $list;
	}

	public function mainParent() {
		$parent = null;
		if ($this->parent) {
			$parent = $this->parent;
			if ($this->parent->parent) {
				$parent = $this->parent->parent;
				if ($this->parent->parent->parent) {
					$parent = $this->parent->parent->parent;
					if ($this->parent->parent->parent->parent) $parent = $this->parent->parent->parent->parent;
				}
			}
		}
		return $parent;
	}

	public function getCategoryTree() {
		return $this->getCategoryParentRecursive();
	}

	protected function getCategoryParentRecursive() {
		$items = [];
		if ($this->parent) {
			$items[] = $this->parent;
			$items = array_merge($items, $this->parent->getCategoryParentRecursive());
		}
		return $items;
	}
}
