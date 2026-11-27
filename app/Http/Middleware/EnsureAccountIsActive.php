<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\PostLoginRedirectService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an inactive account on its next request. This covers open
 * sessions, "remember me" cookies and a two-factor step that was already
 * pending when the account was switched off.
 */
class EnsureAccountIsActive
{
    public function __construct(private PostLoginRedirectService $redirects) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        $locale = $this->redirects->resolveLocale($request);
        app()->setLocale($locale);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = __('This account is inactive. Please contact Maison Anversa.');

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 401);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()->route('maison.home', ['locale' => $locale]);
    }
}
