<?php

namespace Splicewire\Beam\Embed\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Splicewire\Beam\Embed\TurnstileHumanityCheck;

/**
 * The bot gate sits in front of issuing a Visitor session token on a public, unauthenticated,
 * LLM-backed endpoint — i.e. in front of the estate's cost-bomb. Two failure modes are cheap to
 * introduce and expensive to notice:
 *
 *  1. treating a MISSING token as anything but an immediate refusal — an absent header would be POSTed
 *     to Cloudflare as an empty string, and any change in how a falsy `success` is read would turn the
 *     gate into a pass-through;
 *  2. reading the siteverify response loosely (`$response->json('success')` truthiness, a missing key
 *     defaulting to true) — Cloudflare returns `success: false` with a 200, so a lenient read opens the
 *     gate on every rejected challenge.
 *
 * Both are asserted against a faked HTTP layer, so this stays a package-level unit test.
 */
class TurnstileHumanityCheckTest extends TestCase
{
    public function test_a_missing_token_is_refused_without_calling_cloudflare_at_all(): void
    {
        Http::fake();

        $this->assertFalse((new TurnstileHumanityCheck('secret'))->passes(new Request));

        Http::assertNothingSent();
    }

    public function test_an_empty_token_is_refused_without_calling_cloudflare(): void
    {
        Http::fake();

        $request = Request::create('/', 'POST', ['cf_turnstile_response' => '']);

        $this->assertFalse((new TurnstileHumanityCheck('secret'))->passes($request));

        Http::assertNothingSent();
    }

    public function test_a_verified_token_passes_and_the_secret_is_sent_server_side(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $request = Request::create('/', 'POST', [], [], [], ['HTTP_CF_TURNSTILE_RESPONSE' => 'tok']);

        $this->assertTrue((new TurnstileHumanityCheck('s3cret'))->passes($request));

        Http::assertSent(function ($sent) {
            return $sent['secret'] === 's3cret' && $sent['response'] === 'tok';
        });
    }

    public function test_the_token_may_also_arrive_as_a_form_field(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true]),
        ]);

        $request = Request::create('/', 'POST', ['cf_turnstile_response' => 'tok']);

        $this->assertTrue((new TurnstileHumanityCheck('secret'))->passes($request));
    }

    public function test_a_rejected_challenge_is_refused_even_though_cloudflare_answers_200(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);

        $request = Request::create('/', 'POST', ['cf_turnstile_response' => 'forged']);

        $this->assertFalse((new TurnstileHumanityCheck('secret'))->passes($request));
    }

    public function test_a_response_with_no_success_key_defaults_closed(): void
    {
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([]),
        ]);

        $request = Request::create('/', 'POST', ['cf_turnstile_response' => 'tok']);

        $this->assertFalse((new TurnstileHumanityCheck('secret'))->passes($request));
    }
}
