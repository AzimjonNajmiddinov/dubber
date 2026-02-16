<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPasswordProtection
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('admin_authenticated')) {
            return $next($request);
        }

        if ($request->isMethod('POST') && $request->has('admin_password')) {
            $password = config('app.admin_password');

            if (! $password) {
                abort(503, 'ADMIN_PASSWORD not configured.');
            }

            if ($request->input('admin_password') === $password) {
                $request->session()->put('admin_authenticated', true);

                return redirect()->intended($request->url());
            }

            return response()->view('admin.login', [
                'error' => 'Invalid password.',
                'intended' => $request->input('intended', $request->url()),
            ]);
        }

        return response()->view('admin.login', [
            'error' => null,
            'intended' => $request->fullUrl(),
        ]);
    }
}
