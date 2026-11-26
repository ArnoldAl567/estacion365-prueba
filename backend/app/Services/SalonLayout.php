<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalonLayout
{
    public function read(): array
    {
        return $this->decode(DB::table('salon_layout')->where('id', 1)->first());
    }

    public function resolveTable(array $customer): array
    {
        if ($customer['method'] !== 'table') {
            return $customer;
        }

        // Take the layout lock before locking an order, just as layout updates do.
        $layout = $this->decode(DB::table('salon_layout')->where('id', 1)->sharedLock()->first());
        $id = $customer['tableId'] ?? null;
        if ($id !== null) {
            $table = $this->tables($layout['zones'])->get($id);
            abort_unless($table, 409, 'Esta mesa ya no está en el plano. Vuelve a seleccionar una mesa.');
            $customer['tableLabel'] = $table['label'];
        }

        return $customer;
    }

    public function save(array $zones, int $revision): array
    {
        $zoneNames = collect($zones)->map(fn ($zone) => mb_strtoupper($zone['name']));
        $tables = collect($zones)->flatMap(fn ($zone) => $zone['tables']);
        $tableNames = $tables->map(fn ($table) => $this->tableKey($table['label']));
        if ($zoneNames->duplicates()->isNotEmpty() || $tableNames->duplicates()->isNotEmpty() || $tables->pluck('id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['zones' => 'Usa nombres distintos para los salones y para todas las mesas.']);
        }
        abort_if($tables->count() > 160, 422, 'El plano admite hasta 160 mesas.');

        return DB::transaction(function () use ($zones, $revision) {
            $layout = $this->decode(DB::table('salon_layout')->where('id', 1)->lockForUpdate()->first());
            abort_unless($revision === $layout['revision'], 409, 'Otra persona cambió el plano. Cierra la edición y vuelve a abrirla.');
            $previous = $this->tables($layout['zones'])->keyBy(fn ($table) => $this->tableKey($table['label']));
            $next = $this->tables($zones);
            $orders = Order::where('delivery_method', 'table')->whereNull('table_closed_at')
                ->whereIn('status', ['pending', 'preparing', 'ready', 'delivered'])
                ->whereNotNull('table_label')->orderBy('id')->lockForUpdate()->get();
            $unmapped = $orders->map(fn ($order) => $this->tableKey($order->table_label))
                ->reject(fn ($key) => $previous->has($key))->flip();

            foreach ($orders as $order) {
                $old = $previous->get($this->tableKey($order->table_label));
                if (! $old) {
                    continue;
                }
                $table = $next->get($old['id']);
                abort_unless($table, 409, 'La mesa '.$old['label'].' tiene pedidos activos. Finalízalos antes de quitarla.');
                if ($table['label'] !== $old['label']) {
                    abort_if($unmapped->has($this->tableKey($table['label'])), 409, 'Ya hay pedidos activos con el nombre '.$table['label'].'. Usa otro nombre.');
                    $order->table_label = $table['label'];
                    $order->revision++;
                    $order->save();
                }
            }

            DB::table('salon_layout')->where('id', 1)->update([
                'revision' => $revision + 1,
                'zones' => json_encode($zones, JSON_THROW_ON_ERROR),
            ]);

            return ['revision' => $revision + 1, 'zones' => $zones];
        });
    }

    private function tables(array $zones): \Illuminate\Support\Collection
    {
        return collect($zones)->flatMap(fn ($zone) => $zone['tables'])->keyBy('id');
    }

    private function decode(object $row): array
    {
        return ['revision' => (int) $row->revision, 'zones' => json_decode($row->zones, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function tableKey(string $label): string
    {
        $label = trim($label);
        return preg_match('/^(?:mesa\s*|m\s*)?0*(\d+)$/i', $label, $parts)
            ? 'M'.$parts[1]
            : mb_strtoupper($label);
    }
}
