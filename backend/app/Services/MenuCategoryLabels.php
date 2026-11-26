<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class MenuCategoryLabels
{
    public static function names(): object
    {
        return (object) DB::table('menu_category_labels')->pluck('name', 'category')->all();
    }
}
