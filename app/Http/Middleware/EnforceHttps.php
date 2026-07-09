<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('app.force_https') && ! $request->isSecure()) {
            return redirect()->secure($request->getRequestUri(), [], 308);
        }

        return $next($request);
    }
}
