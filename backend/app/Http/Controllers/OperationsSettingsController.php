<?php

namespace App\Http\Controllers;

use App\Services\MenuCategoryLabels;
use App\Services\OperationsAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperationsSettingsController extends Controller
{
    public function show(): array
    {
        return [
            'centers' => DB::table('production_centers')->orderBy('name')->get(),
            'routes' => DB::table('production_routes')->orderBy('category')->get(),
            'categories' => $this->categories(),
            'categoryLabels' => MenuCategoryLabels::names(),
            'methods' => DB::table('payment_methods')->orderBy('name')->get(),
        ];
    }

    public function center(Request $request): array
    {
        $data = $request->validate([
            'code' => 'required|in:kitchen', 'name' => 'required|in:Cocina',
            'printer' => 'nullable|string|max:160', 'active' => 'required|accepted',
        ]);
        abort_if($data['code'] === 'kitchen' && ! $data['active'], 422, 'Cocina debe seguir disponible para las categorías sin asignación.');
        $this->save('production_centers', $data, array_keys(collect($data)->except('code')->all()), $request, 'config.centro');
        return $this->show();
    }

    public function printer(Request $request): array
    {
        $data = $request->validate([
            'code' => 'required|in:kitchen|exists:production_centers,code',
            'printer' => 'present|nullable|string|max:160',
        ]);
        DB::transaction(function () use ($data, $request) {
            DB::table('production_centers')->where('code', $data['code'])->update(['printer' => $data['printer']]);
            OperationsAudit::record($request->user()->id, 'config.impresora', 'centro', $data['code']);
        });
        return $this->show();
    }

    public function route(Request $request): array
    {
        $data = $request->validate($this->categoryRules());
        DB::transaction(function () use ($data, $request) {
            DB::table('production_routes')->upsert([$data], ['category'], ['center']);
            OperationsAudit::record($request->user()->id, 'config.ruta', 'categoria', $data['category'], ['centro' => $data['center']]);
        });
        return $this->show();
    }

    public function category(Request $request): array
    {
        $data = $request->validate([...$this->categoryRules(), 'name' => 'required|string|max:80']);
        DB::transaction(function () use ($data, $request) {
            $center = DB::table('production_centers')->where('code', $data['center'])->sharedLock()->first();
            abort_unless($center?->active, 422, 'Selecciona un centro de preparación activo.');
            DB::table('menu_category_labels')->upsert([
                ['category' => $data['category'], 'name' => $data['name']],
            ], ['category'], ['name']);
            DB::table('production_routes')->upsert([
                ['category' => $data['category'], 'center' => $data['center']],
            ], ['category'], ['center']);
            OperationsAudit::record($request->user()->id, 'config.categoria', 'categoria', $data['category'], [
                'nombre' => $data['name'], 'centro' => $data['center'],
            ]);
        });

        return $this->show();
    }

    private function categories(): array
    {
        return DB::table('menu_products')->whereNotNull('category_id')->distinct()->orderBy('category_id')->pluck('category_id')->all();
    }

    private function categoryRules(): array
    {
        return [
            'category' => ['required', 'string', 'max:64', Rule::in([...$this->categories(), 'menu-dia'])],
            'center' => ['required', 'in:kitchen', Rule::exists('production_centers', 'code')->where('active', true)],
        ];
    }

    public function paymentMethod(Request $request): array
    {
        $data = $request->validate([
            'code' => 'required|alpha_dash:ascii|lowercase|max:32', 'name' => 'required|string|max:80',
            'cash' => 'required|boolean', 'active' => 'required|boolean',
        ]);
        $this->save('payment_methods', $data, ['name', 'cash', 'active'], $request, 'config.medio_pago');
        return $this->show();
    }

    private function save(string $table, array $data, array $fields, Request $request, string $action): void
    {
        DB::transaction(function () use ($table, $data, $fields, $request, $action) {
            DB::table($table)->upsert([$data], ['code'], $fields);
            OperationsAudit::record($request->user()->id, $action, $table, $data['code']);
        });
    }
}
