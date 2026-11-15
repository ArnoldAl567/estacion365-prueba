<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request): array
    {
        return [
            'initialized' => User::exists(),
            'user' => $request->user()?->active ? $request->user()->profile() : null,
            'csrfToken' => csrf_token(),
        ];
    }

    public function setup(Request $request): array
    {
        if (config('restaurant.cloud_staging')) {
            $code = config('restaurant.setup_code');
            $provided = $request->input('setup_code');
            abort_unless(is_string($code) && $code !== '' && is_string($provided) && hash_equals($code, $provided), 403, 'Ingresa el código de activación del entorno de pruebas.');
        }

        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'username' => 'required|alpha_dash:ascii|min:3|max:40',
            'password' => 'required|string|min:10|max:128|confirmed',
        ]);
        $user = DB::transaction(function () use ($data) {
            DB::select('select pg_advisory_xact_lock(3652026)');
            abort_if(User::exists(), 409, 'El administrador ya está configurado. Inicia sesión.');

            return User::create([...$data, 'username' => strtolower($data['username']),
                'email' => strtolower($data['username']).'@local.estacion365', 'role' => 'admin', 'active' => true]);
        });
        Auth::login($user);
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function login(Request $request): array
    {
        $data = $request->validate(['username' => 'required|string|max:40', 'password' => 'required|string|max:128']);
        if (! Auth::attempt(['username' => strtolower(trim($data['username'])), 'password' => $data['password'], 'active' => true])) {
            throw ValidationException::withMessages(['username' => 'Usuario o contraseña incorrectos.']);
        }
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function logout(Request $request): array
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->show($request);
    }
}
