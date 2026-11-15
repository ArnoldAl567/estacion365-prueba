<?php

namespace App\Http\Controllers;

use App\Services\SalonLayout;
use Illuminate\Http\Request;

class SalonLayoutController extends Controller
{
    public function show(SalonLayout $layout): array
    {
        return $layout->read();
    }

    public function update(Request $request, SalonLayout $layout): array
    {
        $data = $request->validate([
            'revision' => 'required|integer|min:0',
            'zones' => 'present|array|list|max:20',
            'zones.*' => 'required|array:id,name,tables',
            'zones.*.id' => 'required|uuid|distinct:ignore_case',
            'zones.*.name' => 'required|string|max:60',
            'zones.*.tables' => 'present|array|list|max:80',
            'zones.*.tables.*' => 'required|array:id,label',
            'zones.*.tables.*.id' => 'required|uuid|distinct:ignore_case',
            'zones.*.tables.*.label' => 'required|string|max:40',
        ], [
            'zones.*.name.required' => 'Escribe el nombre de cada salón.',
            'zones.*.tables.*.label.required' => 'Escribe el nombre de cada mesa.',
        ]);

        return $layout->save($data['zones'], (int) $data['revision']);
    }
}
