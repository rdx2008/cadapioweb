<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DriverResource;
use App\Models\User;
use App\Services\CourierPresence;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CourierAuthController extends Controller
{
    public function __construct(private CourierPresence $presence) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200', 'device_name' => 'required|string|max:100']);
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->access_enabled) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }
        abort_unless($user->role === 'courier', 403, 'Este aplicativo é exclusivo para entregadores.');
        $token = $user->createToken($data['device_name']);
        Audit::record('courier.api.login', ['user_id' => $user->id, 'token_id' => $token->accessToken->id, 'device_name' => $data['device_name']]);
        $driver = $this->presence->driver($user->id);

        return response()->json(['token' => $token->plainTextToken, 'token_type' => 'Bearer'] + $this->profile($user, $driver), 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        DB::table('courier_devices')->where('user_id', $request->user()->id)->where('token_id', $token->id)->delete();
        $token->delete();
        Audit::record('courier.api.logout', ['user_id' => $request->user()->id, 'token_id' => $token->id]);

        return response()->json(['message' => 'Sessão encerrada neste dispositivo.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user(), $this->presence->driver($request->user()->id)));
    }

    public function device(Request $request): JsonResponse
    {
        $data = $request->validate(['platform' => 'required|in:android,ios', 'push_token' => 'required|string|min:10|max:512', 'app_version' => 'nullable|string|max:40']);
        $user = $request->user();
        $device = ['token_id' => $user->currentAccessToken()->id, 'platform' => $data['platform'], 'app_version' => $data['app_version'] ?? null, 'last_seen_at' => now(), 'updated_at' => now()];
        $existing = DB::table('courier_devices')->where('user_id', $user->id)->where('push_token', $data['push_token']);
        if ($existing->exists()) {
            $existing->update($device);
        } else {
            DB::table('courier_devices')->insert($device + ['user_id' => $user->id, 'push_token' => $data['push_token'], 'created_at' => now()]);
        }

        return response()->json(['message' => 'Dispositivo registrado para notificações.', 'push_delivery' => 'pending_provider']);
    }

    /**
     * @return array{driver: DriverResource, presence: array<string, mixed>, intervals: array{heartbeat_seconds: int, presence_ttl_seconds: int, checkin_radius_meters: int, max_accuracy_meters: int}}
     */
    private function profile(User $user, object $driver): array
    {
        $driver->name = $user->name;
        $driver->email = $user->email;

        return ['driver' => new DriverResource($driver), 'presence' => $this->presence->state($driver), 'intervals' => ['heartbeat_seconds' => CourierPresence::HEARTBEAT_SECONDS, 'presence_ttl_seconds' => CourierPresence::PRESENCE_TTL_SECONDS, 'checkin_radius_meters' => CourierPresence::CHECKIN_RADIUS_METERS, 'max_accuracy_meters' => CourierPresence::MAX_ACCURACY_METERS]];
    }
}
