<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function loginPage(Request $request): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/projects');
        }

        $target = $request->query('redirectTo');
        $safeTarget = is_string($target)
            && str_starts_with($target, '/')
            && ! str_starts_with($target, '//')
            && ! str_contains($target, '\\')
            && ! preg_match('/[\x00-\x1F\x7F]/', $target)
            && ! str_starts_with($target, '/auth/login')
                ? $target
                : '/projects';

        return Inertia::render('Auth/Login', ['redirectTo' => $safeTarget]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget('current_project_id');

        return response()->json(['data' => $this->userData($request)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userData($request)]);
    }

    public function logout(Request $request): \Illuminate\Http\Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function userData(Request $request): array
    {
        return $request->user()->only(['id', 'name', 'email']);
    }
}
