<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(): array
    {
        return ['users' => User::orderBy('id')->get()->map(fn ($user) => $user->profile())];
    }

    public function store(Request $request): array
    {
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'username' => 'required|alpha_dash:ascii|min:3|max:40|unique:users,username',
            'password' => 'required|string|min:10|max:128',
            'role' => ['required', Rule::in(['admin', 'kitchen'])],
        ], ['username.unique' => 'Este usuario ya está registrado.', 'password.min' => 'Usa al menos 10 caracteres para la contraseña.']);
        $user = User::create([...$data, 'email' => $data['username'].'@local.estacion365', 'active' => true]);

        return ['user' => $user->profile()];
    }

    public function update(Request $request, User $user): array
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:120',
            'role' => ['sometimes', Rule::in(['admin', 'kitchen'])],
            'active' => 'sometimes|boolean',
            'password' => 'sometimes|required|string|min:10|max:128',
        ]);
        if (array_key_exists('active', $data)) {
            $data['active'] = (bool) $data['active'];
        }

        return DB::transaction(function () use ($request, $user, $data) {
            DB::select('select pg_advisory_xact_lock(3652027)');
            $current = User::findOrFail($user->id);
            $removesAdmin = ($data['active'] ?? $current->active) === false || ($data['role'] ?? $current->role) !== 'admin';
            abort_if($current->id === $request->user()->id && $removesAdmin, 422, 'Usa otra cuenta administradora para cambiar tu propio acceso.');
            abort_if($current->role === 'admin' && $current->active && $removesAdmin && User::where('role', 'admin')->where('active', true)->count() <= 1,
                422, 'Debe quedar al menos un administrador activo.');
            $current->fill($data)->save();
            if (array_key_exists('password', $data) || ! $current->active) {
                DB::table('sessions')->where('user_id', $current->id)->delete();
            }

            return ['user' => $current->profile()];
        });
    }
}
