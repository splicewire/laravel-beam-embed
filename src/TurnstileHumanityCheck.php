<?php

namespace Splicewire\Beam\Embed;

use Splicewire\Beam\Embed\Contracts\HumanityCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The Cloudflare Turnstile {@see HumanityCheck} driver (DIE-10). It verifies the
 * `cf-turnstile-response` token the widget attaches against Cloudflare's siteverify
 * endpoint. Shipped but NOT provisioned until launch — bound only when
 * `config('embed.humanity.driver') === 'turnstile'` and a secret is configured; the
 * seam never depends on Turnstile.
 */
class TurnstileHumanityCheck implements HumanityCheck
{
    public function __construct(private string $secret) {}

    public function passes(Request $request): bool
    {
        $token = $request->header('CF-Turnstile-Response') ?? $request->input('cf_turnstile_response');

        if (! is_string($token) || $token === '') {
            return false;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $this->secret,
            'response' => $token,
            'remoteip' => $request->ip(),
        ]);

        return (bool) $response->json('success', false);
    }
}
