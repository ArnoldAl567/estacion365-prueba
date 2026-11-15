<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class OrderModifierController extends Controller
{
    public function index(): array
    {
        return ['modifiers' => DB::table('order_modifiers')->orderBy('name')->get(['id', 'name'])];
    }

    public function store(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:40|not_regex:/[,\r\n]/']);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Escribe el nombre de la opción.');
        $saved = DB::table('order_modifiers')->insertOrIgnore([
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
        ]);
        abort_unless($saved, 409, 'Ya existe una opción con ese nombre.');

        return $this->index();
    }

    public function destroy(int $id): Response
    {
        DB::table('order_modifiers')->where('id', $id)->delete();

        return response()->noContent();
    }
}
