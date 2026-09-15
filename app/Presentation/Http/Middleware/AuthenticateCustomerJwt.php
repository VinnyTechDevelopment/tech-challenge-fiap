<?php

namespace App\Presentation\Http\Middleware;

use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

/**
 * Valida o JWT emitido pela Lambda lambda-auth-cpf (repositório separado).
 * Não usa a guard "api"/jwt-auth existente (que resolve App\Models\User) —
 * este token representa um Customer autenticado por CPF, não um usuário de
 * staff.
 */
class AuthenticateCustomerJwt
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Token não informado.'], 401);
        }

        $token = substr($header, 7);
        $secret = config('services.customer_jwt.secret');

        if (!$secret) {
            return response()->json(['message' => 'Autenticação de cliente não configurada.'], 500);
        }

        try {
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        } catch (ExpiredException) {
            return response()->json(['message' => 'Token expirado.'], 401);
        } catch (\Throwable) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        if (($decoded->type ?? null) !== 'customer') {
            return response()->json(['message' => 'Tipo de token inválido para esta rota.'], 401);
        }

        $request->attributes->set('customer_id', $decoded->sub);
        $request->attributes->set('customer_document', $decoded->document ?? null);

        return $next($request);
    }
}
