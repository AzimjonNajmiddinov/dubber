<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsForExtension
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin', '');

        $allowed = str_starts_with($origin, 'chrome-extension://')
            || str_starts_with($origin, 'moz-extension://')
            || str_starts_with($origin, 'http://localhost')
            || str_starts_with($origin, 'http://127.0.0.1');

        if ($request->isMethod('OPTIONS')) {
            return response('', 204)
                ->header('Access-Control-Allow-Origin', $allowed ? $origin : '')
                ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept')
                ->header('Access-Control-Max-Age', '86400');
        }

        $response = $next($request);

        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
        }

        return $response;
    }
}
