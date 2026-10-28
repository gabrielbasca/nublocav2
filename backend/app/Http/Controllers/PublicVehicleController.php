<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\MessageTemplate;
use App\Models\Vehicle;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PublicVehicleController extends Controller
{
    public function show(Request $request, string $publicToken): View
    {
        $vehicle = Vehicle::query()->where('public_token', $publicToken)->firstOrFail();
        $submissionToken = Str::random(64);
        $request->session()->put($this->sessionKey($publicToken), $submissionToken);

        return view('vehicles.public-contact', [
            'vehicle' => $vehicle,
            'submissionToken' => $submissionToken,
            'templates' => MessageTemplate::query()
                ->where('type', 'contact')
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'body']),
        ]);
    }

    public function contact(
        Request $request,
        string $publicToken,
        FirebaseNotificationService $notifications,
    ): RedirectResponse {
        $vehicle = Vehicle::query()->where('public_token', $publicToken)->firstOrFail();

        $data = $request->validate([
            'message_template_id' => [
                'required',
                'integer',
                'exists:message_templates,id,type,contact,is_active,1',
            ],
            'company' => ['nullable', 'string', 'max:255'],
            'submission_token' => ['required', 'string', 'size:64'],
        ]);

        $sessionKey = $this->sessionKey($publicToken);
        $expectedToken = $request->session()->get($sessionKey);
        if (! is_string($expectedToken) || ! hash_equals($expectedToken, $data['submission_token'])) {
            return $this->successRedirect($publicToken);
        }

        $request->session()->forget($sessionKey);
        $idempotencyKey = 'public-vehicle-contact:'.hash(
            'sha256',
            $publicToken.':'.$data['submission_token'],
        );
        if (! Cache::add($idempotencyKey, true, now()->addDay()) || filled($data['company'] ?? null)) {
            return $this->successRedirect($publicToken);
        }

        DB::transaction(function () use ($vehicle, $data, $notifications): void {
            $conversation = Conversation::create([
                'vehicle_id' => $vehicle->id,
                'started_by_user_id' => null,
                'status' => 'open',
            ]);

            $template = MessageTemplate::findOrFail($data['message_template_id']);
            $message = $conversation->messages()->create([
                'sender_user_id' => null,
                'message_template_id' => $template->id,
                'body_snapshot' => $template->body,
            ]);

            $notifications->queueConversationMessage(
                message: $message,
                recipient: $vehicle->user,
                isContactRequest: true,
            );
        });

        return $this->successRedirect($publicToken);
    }

    private function sessionKey(string $publicToken): string
    {
        return 'public_vehicle_contact:'.$publicToken;
    }

    private function successRedirect(string $publicToken): RedirectResponse
    {
        return redirect()
            ->route('vehicles.public.show', ['publicToken' => $publicToken])
            ->with('status', 'Your message has been sent to the vehicle owner.');
    }
}
