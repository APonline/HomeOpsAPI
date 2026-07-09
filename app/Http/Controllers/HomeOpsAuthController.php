<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\HomeOpsV0;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class HomeOpsAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 422);
        }

        $token = $this->issueToken($request, $user, (bool) ($data['remember'] ?? true));
        $homeId = HomeOpsV0::primaryHomeId((int) $user->id);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->serializeUser($user),
            'selected_home_id' => $homeId,
        ]);
    }

    public function register(Request $request)
    {
        abort_unless((bool) env('HOMEOPS_PUBLIC_SIGNUP', false), 403, 'Public signup is disabled for this HomeOps build.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'property_name' => ['nullable', 'string', 'max:160'],
            'property_type' => ['nullable', 'string', 'max:80'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
        ]);

        $homeId = HomeOpsV0::createStarterHomeForUser(
            (int) $user->id,
            $data['property_name'] ?? 'My Home',
            $data['property_type'] ?? 'primary_residence'
        );

        $token = $this->issueToken($request, $user, true);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->serializeUser($user),
            'selected_home_id' => $homeId,
        ], 201);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->serializeUser($user),
            'homes' => HomeOpsV0::homesForUser((int) $user->id)->get(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ]);

        $payload = [
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
        ];

        if (Schema::hasColumn('users', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update($payload);

        $freshUser = User::find($user->id);

        return response()->json([
            'ok' => true,
            'message' => 'Account profile updated.',
            'user' => $this->serializeUser($freshUser),
        ]);
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Your current password is incorrect.',
                'errors' => [
                    'current_password' => ['Your current password is incorrect.'],
                ],
            ], 422);
        }

        $payload = [
            'password' => Hash::make($data['password']),
        ];

        if (Schema::hasColumn('users', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update($payload);

        return response()->json([
            'ok' => true,
            'message' => 'Password updated.',
        ]);
    }

    public function logout(Request $request)
    {
        $hash = $request->attributes->get('homeops_token_hash');

        if ($hash && Schema::hasTable('homeops_api_tokens')) {
            DB::table('homeops_api_tokens')
                ->where('token_hash', $hash)
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json(['ok' => true]);
    }

    private function issueToken(Request $request, User $user, bool $remember): string
    {
        abort_unless(Schema::hasTable('homeops_api_tokens'), 503, 'Run migrations before logging in.');

        $plainToken = Str::random(80);
        $days = max(1, (int) env('HOMEOPS_AUTH_TOKEN_DAYS', $remember ? 30 : 7));

        DB::table('homeops_api_tokens')->insert([
            'user_id' => $user->id,
            'name' => $remember ? 'HomeOps web session' : 'HomeOps short session',
            'token_hash' => hash('sha256', $plainToken),
            'abilities' => json_encode(['*']),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'expires_at' => now()->addDays($days),
            'last_used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $plainToken;
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
