<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_oauth_state');

        if ($request->boolean('error') || ! $request->filled('code')
            || ! $request->filled('state') || ! hash_equals((string) $expectedState, (string) $request->query('state'))) {
            return redirect()->route('login')
                ->with('status', 'Google login gagal atau dibatalkan. Silakan coba lagi.');
        }

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if ($tokenResponse->failed() || blank($tokenResponse->json('access_token'))) {
            report(new \RuntimeException('Google OAuth token exchange failed: '.$tokenResponse->body()));

            return redirect()->route('login')
                ->with('status', 'Google login gagal. Silakan coba lagi.');
        }

        $googleUser = Http::withToken($tokenResponse->json('access_token'))
            ->get('https://www.googleapis.com/oauth2/v3/userinfo')
            ->json();

        $googleId = $googleUser['sub'] ?? null;
        $email = isset($googleUser['email']) ? mb_strtolower(trim($googleUser['email'])) : null;

        if (blank($googleId) || blank($email) || ($googleUser['email_verified'] ?? false) === false) {
            return redirect()->route('login')
                ->with('status', 'Gagal mengambil data akun Google kamu. Pastikan email Google sudah terverifikasi.');
        }

        try {
            $customer = DB::transaction(function () use ($googleId, $email, $googleUser): Customer {
                $customerByGoogle = Customer::withTrashed()->where('google_id', $googleId)->first();
                $customerByEmail = Customer::withTrashed()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->first();

                if ($customerByGoogle !== null
                    && $customerByEmail !== null
                    && $customerByGoogle->isNot($customerByEmail)) {
                    throw ValidationException::withMessages([
                        'email' => 'This Google account is linked to another customer profile.',
                    ]);
                }

                $customer = $customerByGoogle ?? $customerByEmail;

                if ($customer === null && User::withTrashed()
                    ->whereRaw('LOWER(email) = ?', [$email])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'email' => 'This email belongs to an internal account. Use the internal login instead.',
                    ]);
                }

                if ($customer !== null && filled($customer->google_id) && $customer->google_id !== $googleId) {
                    throw ValidationException::withMessages([
                        'email' => 'This customer profile is linked to another Google account.',
                    ]);
                }

                if ($customer?->hasLoginAccount() && ! $customer->is_active) {
                    throw ValidationException::withMessages([
                        'email' => 'Akun kamu sedang dinonaktifkan. Hubungi admin.',
                    ]);
                }

                if ($customer?->trashed()) {
                    $customer->restore();
                }

                $customer ??= new Customer;
                $shouldActivate = ! $customer->exists || ! $customer->hasLoginAccount();
                $customer->forceFill([
                    'name' => $customer->name ?: ($googleUser['name'] ?? $googleUser['given_name'] ?? 'Google User'),
                    'email' => $customer->email ?: $email,
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'is_active' => $shouldActivate ? true : $customer->is_active,
                    'record_status' => 'saved',
                ])->save();

                return $customer->refresh();
            });
        } catch (ValidationException $exception) {
            return redirect()->route('login')
                ->with('status', $exception->validator->errors()->first());
        }

        if (! $customer->is_active) {
            return redirect()->route('login')
                ->with('status', 'Akun kamu sedang dinonaktifkan. Hubungi admin.');
        }

        Auth::guard('customer')->login($customer, remember: true);

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
