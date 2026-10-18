<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User && $request->session()->has('login.id')) {
            $user = User::query()->find($request->session()->get('login.id'));
        }
        if ($user instanceof User
            && (int) $request->session()->get('admin_session_version', 0) !== $user->session_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('recovery.sign_in_again'));
        }

        return $next($request);
    }
}
