<?php

namespace App\Http\Controllers;

use App\Domain\Customer\ValueObjects\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\JWTGuard;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'document' => 'required|string|max:14|unique:users,document',
            'password' => 'required|confirmed|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        $document = new Document((string) request('document'));

        $user = new User;
        $user->name = request('name');
        $user->email = request('email');
        $user->document = $document->getValue();
        $user->password = bcrypt(request('password'));
        $user->save();

        unset($user->password);

        return response()->json(['message' => 'Novo usuário criado com sucesso', 'user' => $user], 201);
    }

    public function login()
    {
        $credentials = request(['email', 'password']);
        /** @var JWTGuard $auth */
        $auth = auth();

        if (! $token = $auth->attempt($credentials)) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
        ]);
    }

    public function me()
    {
        /** @var JWTGuard $auth */
        $auth = auth();

        return response()->json($auth->user());
    }

    public function logout()
    {
        /** @var JWTGuard $auth */
        $auth = auth();
        $auth->logout();

        return response()->json(['message' => 'Usuário deslogado com sucesso']);
    }

    public function refresh()
    {
        /** @var JWTGuard $auth */
        $auth = auth();

        return $this->respondWithToken($auth->refresh());
    }

    protected function respondWithToken($token)
    {
        /** @var JWTGuard $auth */
        $auth = auth();

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $auth->factory()->getTTL() * 60,
        ]);
    }
}
