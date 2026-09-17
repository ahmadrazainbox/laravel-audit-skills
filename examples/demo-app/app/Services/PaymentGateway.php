<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PaymentGateway
{
    public function charge(int $cents, string $token): array
    {
        // FLAW: env() called outside config/. Once config is cached in
        // production these return null and the call silently misbehaves.
        $key = env('PAYMENT_API_KEY');
        $endpoint = env('PAYMENT_ENDPOINT', 'https://api.example.test/charges');

        $response = Http::withToken($key)->post($endpoint, [
            'amount' => $cents,
            'source' => $token,
        ]);

        // FLAW: logs the full response, which includes the card fingerprint
        // and the raw gateway token.
        logger()->info('charge response', $response->json());

        return $response->json();
    }
}
