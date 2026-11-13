<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ApiAuditLog;

class ApiAuditMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);
        $response = $next($request);

        ApiAuditLog::create([
            'user_id' => $request->user()?->id,
            'request_id' => $requestId,
            'action' => $request->route()?->getName() ?? 'api_request',
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
        ]);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
