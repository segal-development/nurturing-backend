<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Asignar rol de usuario por defecto
        $user->assignRole('super_admin');

        // Auto-login después del registro (opcional)
        Auth::login($user);

        return response()->json([
            'message' => 'Usuario registrado exitosamente',
            'user' => $this->formatUserResponse($user),
        ], 201);
    }

    /**
     * Login user using session-based authentication.
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Las credenciales proporcionadas son incorrectas.',
                'errors' => ['email' => ['Las credenciales proporcionadas son incorrectas.']],
            ], 422);
        }

        // Regenerar sesión para prevenir session fixation
        $request->session()->regenerate();

        return response()->json([
            'message' => 'Login exitoso',
            'user' => $this->formatUserResponse(Auth::user()),
        ]);
    }

    /**
     * Get authenticated user information.
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->formatUserResponse($request->user()),
        ]);
    }

    /**
     * Logout user and invalidate session.
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Si es una petición con token API (no sesión), revocar el token
        if ($user && $user->currentAccessToken() &&
            ! ($user->currentAccessToken() instanceof \Laravel\Sanctum\TransientToken)) {
            $user->currentAccessToken()->delete();
        }

        // Cerrar sesión web
        Auth::guard('web')->logout();

        // Invalidar sesión y regenerar token CSRF
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logout exitoso',
        ]);
    }

    private function formatUserResponse(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleName(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ];
    }
}
