<?php

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints internos (consumidos pela Lambda lambda-auth-cpf) exigem o
 * header X-Internal-Token com o segredo compartilhado INTERNAL_API_TOKEN.
 */
class AuthenticateInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.internal_api.token');
        $given = (string) $request->header('X-Internal-Token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
