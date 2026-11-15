<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    private const LIMIT = 250;

    private const CAPACITY_MESSAGE = 'La carta admite un máximo de 250 productos, incluidos los agotados. Puedes editar los productos existentes.';

    private const CATEGORIES = ['marinos', 'criollos', 'caldos', 'mix', 'broaster', 'bebidas', 'guarnicion', 'entrada-adicional'];

    public function store(Request $request): JsonResponse
    {
        $identity = $request->validate([
            'kind' => ['required', Rule::in(['carta', 'fondo', 'entrada'])],
            'source_id' => ['required', 'regex:/^nuevo-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
        ]);
        $fields = $this->fields($request, $identity['kind'], true);
        $saved = DB::transaction(function () use ($identity, $fields) {
            DB::select('select pg_advisory_xact_lock(3652028)');
            abort_if(DB::table('menu_products')->count() >= self::LIMIT, 409, self::CAPACITY_MESSAGE);
            $key = $identity['kind'].':'.$identity['source_id'];
            abort_if(DB::table('menu_products')->where('item_key', $key)->exists(), 409, 'Este plato ya se guardó. Actualiza la lista para verlo.');
            DB::table('menu_products')->insert([
                ...$fields,
                'item_key' => $key,
                'source_id' => $identity['source_id'],
                'kind' => $identity['kind'],
                'sort_order' => (int) DB::table('menu_products')->max('sort_order') + 1,
            ]);

            return DB::table('menu_products')->where('item_key', $key)->first();
        });

        return response()->json(['item' => $this->present($saved)], 201);
    }

    public function update(Request $request, string $itemKey): array
    {
        $item = DB::table('menu_products')->where('item_key', $itemKey)->first();
        abort_unless($item, 404, 'Este plato no existe.');
        foreach (['item_key', 'kind', 'source_id'] as $field) {
            abort_if($request->has($field) && $request->input($field) !== $item->$field, 422, 'No se puede cambiar la identidad del plato.');
        }
        $fields = $this->fields($request, $item->kind, false);
        abort_if($fields === [], 422, 'Indica los cambios que deseas guardar.');
        DB::table('menu_products')->where('item_key', $itemKey)->update($fields);

        return ['item' => $this->present(DB::table('menu_products')->where('item_key', $itemKey)->first())];
    }

    public function prices(Request $request): array
    {
        $prices = $request->validate([
            'economico' => 'required|numeric|decimal:0,2|min:0|max:9999.99',
            'ejecutivo' => 'required|numeric|decimal:0,2|min:0|max:9999.99',
        ]);
        DB::transaction(function () use ($prices) {
            DB::select('select pg_advisory_xact_lock(3652029)');
            foreach ($prices as $type => $amount) {
                DB::table('menu_prices')->updateOrInsert(['type' => $type], ['price_cents' => (int) round($amount * 100)]);
            }
        });

        return ['prices' => $prices];
    }

    public function upload(Request $request): array
    {
        $request->validate(['image' => [
            'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120',
            'dimensions:min_width=80,min_height=80,max_width=6000,max_height=6000',
        ]]);
        abort_unless(extension_loaded('gd') && function_exists('imagewebp'), 503, 'Activa la extensión GD con WebP en PHP para guardar fotografías.');
        $dimensions = getimagesize($request->file('image')->getRealPath());
        abort_if(! $dimensions || $dimensions[0] * $dimensions[1] > 16_000_000, 422, 'La fotografía es demasiado grande. Usa una de hasta 16 megapíxeles.');
        $source = imagecreatefromstring(file_get_contents($request->file('image')->getRealPath()));
        abort_unless($source, 422, 'No se pudo leer esta fotografía.');
        $encoded = null;
        try {
            foreach ([1200, 960, 720, 560] as $maxSide) {
                $scale = min(1, $maxSide / max(imagesx($source), imagesy($source)));
                $width = max(1, (int) round(imagesx($source) * $scale));
                $height = max(1, (int) round(imagesy($source) * $scale));
                $canvas = imagecreatetruecolor($width, $height);
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
                try {
                    foreach ([82, 65, 48] as $quality) {
                        ob_start();
                        imagewebp($canvas, null, $quality);
                        $candidate = ob_get_clean();
                        if (strlen($candidate) <= 180 * 1024) {
                            $encoded = $candidate;
                            break;
                        }
                    }
                } finally {
                    imagedestroy($canvas);
                }
                if ($encoded !== null) {
                    break;
                }
            }
        } finally {
            imagedestroy($source);
        }
        abort_if($encoded === null, 422, 'La fotografía tiene demasiado detalle. Prueba con otra.');
        $filename = Str::uuid().'.webp';
        abort_unless(Storage::disk('public')->put('catalog/'.$filename, $encoded), 500, 'No se pudo guardar la fotografía en esta computadora.');

        return ['url' => '/local-api/images/'.$filename];
    }

    public function image(string $filename)
    {
        $disk = Storage::disk('public');
        abort_unless($disk->exists('catalog/'.$filename), 404);

        return response()->file($disk->path('catalog/'.$filename), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function fields(Request $request, string $kind, bool $creating): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $presence = $creating ? 'required' : 'sometimes|required';
        $data = $request->validate([
            'name' => $presence.'|string|min:2|max:120',
            'category_id' => $kind === 'carta' ? [...explode('|', $presence), Rule::in(self::CATEGORIES)] : 'nullable',
            'price' => ['nullable', Rule::requiredIf($creating && $kind === 'carta'), 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'image_url' => ($creating ? 'required' : 'sometimes|nullable').'|string|max:2048',
            'active' => $presence.'|boolean',
        ]);
        $fields = [];
        foreach (['name', 'active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $data[$field];
            }
        }
        if ($kind !== 'carta') {
            $fields['category_id'] = null;
            $fields['price_cents'] = null;
        } else {
            if (array_key_exists('category_id', $data)) {
                $fields['category_id'] = $data['category_id'];
            }
            if (array_key_exists('price', $data)) {
                $fields['price_cents'] = $data['price'] === null ? null : (int) round($data['price'] * 100);
            }
        }
        if (array_key_exists('image_url', $data)) {
            $url = $data['image_url'];
            $valid = $url === null || preg_match('~^/?images/[a-zA-Z0-9/_\-.]+$~', $url)
                || (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https');
            if ($url && preg_match('~^/local-api/images/([0-9a-f-]{36}\.webp)$~', $url, $matches)) {
                $valid = Storage::disk('public')->exists('catalog/'.$matches[1]);
            }
            if (! $valid) {
                throw ValidationException::withMessages(['image_url' => 'Sube una fotografía o usa una dirección HTTPS válida.']);
            }
            $fields['image_url'] = $url;
        }

        return $fields;
    }

    private function present(object $item): array
    {
        return [...((array) $item), 'price' => $item->price_cents === null ? null : $item->price_cents / 100];
    }
}
