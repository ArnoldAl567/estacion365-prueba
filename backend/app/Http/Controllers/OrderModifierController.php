<?php

namespace App\Http\Controllers;

use App\Services\OperationsAudit;
use Illuminate\Database\UniqueConstraintViolationException;
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
        $name = $this->validatedName($request);
        DB::transaction(function () use ($request, $name) {
            $key = mb_strtolower($name);
            $saved = DB::table('order_modifiers')->insertOrIgnore(['name' => $name, 'normalized_name' => $key]);
            abort_unless($saved, 409, 'Ya existe una opción con ese nombre.');
            $id = DB::table('order_modifiers')->where('normalized_name', $key)->value('id');
            OperationsAudit::record($request->user()->id, 'salon.modificador_creado', 'modificador', $id);
        });

        return $this->index();
    }

    public function update(Request $request, int $id): array
    {
        $name = $this->validatedName($request);
        try {
            DB::transaction(function () use ($request, $id, $name) {
                $current = DB::table('order_modifiers')->where('id', $id)->lockForUpdate()->first();
                abort_unless($current, 404, 'La opción de preparación ya no existe.');
                if ($current->name === $name) return;
                DB::table('order_modifiers')->where('id', $id)->update(['name' => $name, 'normalized_name' => mb_strtolower($name)]);
                OperationsAudit::record($request->user()->id, 'salon.modificador_editado', 'modificador', $id, [
                    'nombre_anterior' => $current->name, 'nombre' => $name,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Ya existe una opción con ese nombre.');
        }

        return $this->index();
    }

    private function validatedName(Request $request): string
    {
        $data = $request->validate(['name' => 'required|string|max:40|not_regex:/[,\r\n]/']);
        $name = trim($data['name']);
        abort_if($name === '', 422, 'Escribe el nombre de la opción.');

        return $name;
    }

    public function destroy(Request $request, int $id): Response
    {
        DB::transaction(function () use ($request, $id) {
            if (DB::table('order_modifiers')->where('id', $id)->delete()) {
                OperationsAudit::record($request->user()->id, 'salon.modificador_eliminado', 'modificador', $id);
            }
        });

        return response()->noContent();
    }
}
