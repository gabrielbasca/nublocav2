<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleAlert;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

class PublicVehicleAlertController extends Controller
{
    /**
     * Cooldown between accepted alerts for the same vehicle from the same
     * scanner, in seconds.
     */
    private const COOLDOWN_SECONDS = 300;

    /**
     * Return only safe public information about a vehicle plus the list of
     * supported alert reasons. Never exposes plate, owner identity, user ID,
     * or any contact details.
     */
    public function show(string $token): JsonResponse
    {
        $vehicle = $this->findByToken($token);

        return response()->json([
            'vehicle' => [
                'public_token' => $vehicle->public_token,
            ],
            'alert_reasons' => VehicleAlert::reasons(),
        ]);
    }

    /**
     * Accept an anonymous predefined parking alert for a vehicle.
     *
     * Always returns a generic success payload so a scanner cannot tell
     * whether the owner is online or a notification was actually delivered.
     */
    public function store(
        Request $request,
        string $token,
        FirebaseNotificationService $notifications,
    ): JsonResponse {
        $vehicle = $this->findByToken($token);

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(VehicleAlert::reasons())],
            // Optional anonymous scanner token lets a client avoid duplicate
            // submissions; only its salted hash is ever stored.
            'scanner_token' => ['nullable', 'string', 'min:16', 'max:128'],
            // Honeypot: legitimate clients never send this field.
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        // Silently accept bot submissions without creating anything.
        if (filled($data['company'] ?? null)) {
            return $this->genericSuccess();
        }

        $ipHash = $this->hash((string) $request->ip());
        $scannerHash = isset($data['scanner_token'])
            ? $this->hash($data['scanner_token'])
            : null;

        // Idempotency / replay protection first: an identical alert (same
        // vehicle, reason, and scanner) within the cooldown window is accepted
        // with a generic response but not duplicated or re-notified.
        $idempotencyKey = 'vehicle-alert:'.hash(
            'sha256',
            $vehicle->id.':'.$data['reason'].':'.($scannerHash ?? $ipHash),
        );
        if (! Cache::add($idempotencyKey, true, now()->addSeconds(self::COOLDOWN_SECONDS))) {
            return $this->genericSuccess();
        }

        if ($this->isRateLimited($vehicle, $ipHash, $scannerHash)) {
            return response()->json(['message' => 'Too many alerts. Please try again later.'], 429);
        }

        DB::transaction(function () use ($vehicle, $data, $ipHash, $scannerHash, $notifications): void {
            $alert = $vehicle->alerts()->create([
                'reason' => $data['reason'],
                'status' => VehicleAlert::STATUS_NEW,
                'reporter_ip_hash' => $ipHash,
                'reporter_token_hash' => $scannerHash,
            ]);

            $notifications->queueAccountEvent(
                recipient: $vehicle->user,
                eventId: "vehicle-alert:{$alert->id}",
                title: 'New vehicle alert',
                body: $this->reasonLabel($data['reason']),
                navigationData: [
                    'route' => 'vehicle_alerts',
                    'vehicle_id' => (string) $vehicle->id,
                    'alert_id' => (string) $alert->id,
                ],
            );
        });

        return $this->genericSuccess();
    }

    private function findByToken(string $token): Vehicle
    {
        return Vehicle::query()->where('public_token', $token)->firstOrFail();
    }

    private function isRateLimited(Vehicle $vehicle, string $ipHash, ?string $scannerHash): bool
    {
        // Per-IP limit.
        $ipKey = 'vehicle-alert:ip:'.$ipHash;
        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            return true;
        }

        // Per-vehicle limit protects a single vehicle from alert storms.
        $vehicleKey = 'vehicle-alert:vehicle:'.$vehicle->id;
        if (RateLimiter::tooManyAttempts($vehicleKey, 10)) {
            return true;
        }

        // Cooldown for repeated alerts from the same scanner to this vehicle.
        $cooldownKey = 'vehicle-alert:cooldown:'.$vehicle->id.':'.($scannerHash ?? $ipHash);
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return true;
        }

        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($vehicleKey, 3600);
        RateLimiter::hit($cooldownKey, self::COOLDOWN_SECONDS);

        return false;
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            VehicleAlert::REASON_BLOCKING_ACCESS => 'Your vehicle is blocking access.',
            VehicleAlert::REASON_LIGHTS_ON => 'Your vehicle lights appear to be on.',
            VehicleAlert::REASON_WINDOW_OPEN => 'A window on your vehicle appears to be open.',
            VehicleAlert::REASON_ACCIDENT_DAMAGE => 'Your vehicle may have been damaged.',
            default => 'Someone sent you an alert about your vehicle.',
        };
    }

    private function genericSuccess(): JsonResponse
    {
        return response()->json(['message' => 'Your alert has been sent.'], 201);
    }
}
