<?php

namespace Splicewire\Beam\Embed\Tests\Models;

use Splicewire\Beam\Embed\Models\EmbedKey;
use Splicewire\Beam\Embed\Tests\TestCase;

/**
 * The origin allowlist is the only thing standing between a public `pk_` — which by design leaks, it
 * ships in a third party's page source — and an arbitrary site spawning sessions against a tenant's
 * budget. It is enforced by string matching plus one hand-built regex, and it is entirely
 * host-independent logic, so it belongs in a package unit test rather than behind a tenant harness.
 *
 * The wildcard is where the danger is. `https://*.app.splicewire.test` exists so ONE first-party key
 * serves every tenant subdomain, and it is compiled to a regex. Three classic ways that goes wrong:
 *
 *  - UNANCHORED — `evil.com/?x=app.splicewire.test` or `app.splicewire.test.evil.com` matches;
 *  - a `*` that becomes `.*` — one label silently becomes any depth, so `a.b.c.app.splicewire.test`
 *    (a host an attacker can own under a wildcard-DNS domain) is admitted;
 *  - a `*` that also matches EMPTY — the bare apex slips in through a subdomain-only entry.
 *
 * Each has a named assertion below. The normalisation cases matter too: the middleware falls back to
 * the `Referer` header when `Origin` is absent, and a Referer always carries a path — a matcher
 * comparing raw strings would reject every legitimate Referer-based request.
 */
class EmbedKeyOriginTest extends TestCase
{
    /**
     * @param  list<string>  $origins
     */
    private function keyAllowing(array $origins): EmbedKey
    {
        return new EmbedKey(['allowed_origins' => $origins]);
    }

    public function test_a_wildcard_admits_any_single_label_subdomain(): void
    {
        $key = $this->keyAllowing(['https://*.app.splicewire.test']);

        $this->assertTrue($key->allowsOrigin('https://foodsafety.app.splicewire.test'));
        $this->assertTrue($key->allowsOrigin('https://splicewire.app.splicewire.test'));
        $this->assertTrue($key->allowsOrigin('https://tenant-with-dashes.app.splicewire.test'));
    }

    public function test_a_wildcard_never_admits_the_bare_apex(): void
    {
        // The `*` must consume at least one label. An apex that wants in needs its own entry.
        $key = $this->keyAllowing(['https://*.app.splicewire.test']);

        $this->assertFalse($key->allowsOrigin('https://app.splicewire.test'));
    }

    public function test_a_wildcard_stands_for_exactly_one_label_not_an_arbitrary_depth(): void
    {
        // `*` → `.*` is the single easiest regression to introduce here, and it hands an attacker any
        // host under a wildcard-DNS domain.
        $key = $this->keyAllowing(['https://*.app.splicewire.test']);

        $this->assertFalse($key->allowsOrigin('https://foo.bar.app.splicewire.test'));
        $this->assertFalse($key->allowsOrigin('https://a.b.c.app.splicewire.test'));
    }

    public function test_the_match_is_anchored_so_a_suffix_lookalike_is_refused(): void
    {
        // The attack an unanchored preg_match would allow: register the allowed host as a PREFIX of a
        // domain you control.
        $key = $this->keyAllowing(['https://*.app.splicewire.test']);

        $this->assertFalse($key->allowsOrigin('https://x.app.splicewire.test.evil.com'));
        $this->assertFalse($key->allowsOrigin('https://evil.com'));
    }

    public function test_the_dots_in_a_pattern_are_literal_not_regex_wildcards(): void
    {
        // preg_quote's other job. Without it, `.` matches any character and
        // `https://xxapp.splicewire.test` (a domain an attacker can simply register) is admitted.
        $key = $this->keyAllowing(['https://*.app.splicewire.test']);

        $this->assertFalse($key->allowsOrigin('https://x.appXsplicewire.test'));
    }

    public function test_a_referer_carrying_a_path_still_matches(): void
    {
        // The middleware falls back to Referer when Origin is absent; a Referer always has a path.
        $key = $this->keyAllowing(['https://*.app.splicewire.test']);

        $this->assertTrue($key->allowsOrigin('https://demo.app.splicewire.test/ui/studio'));
        $this->assertTrue($key->allowsOrigin('https://demo.app.splicewire.test/'));
    }

    public function test_exact_entries_still_work_and_compose_with_a_wildcard(): void
    {
        $key = $this->keyAllowing([
            'https://app.splicewire.test',
            'https://*.app.splicewire.test',
        ]);

        $this->assertTrue($key->allowsOrigin('https://app.splicewire.test'));
        $this->assertTrue($key->allowsOrigin('https://tenant.app.splicewire.test'));
        $this->assertFalse($key->allowsOrigin('https://shop.example'));
    }

    public function test_the_scheme_is_part_of_the_comparison(): void
    {
        // An http origin must not satisfy an https entry — the whole point of pinning the scheme is
        // that a downgraded page is a different security context.
        $key = $this->keyAllowing(['https://shop.example']);

        $this->assertTrue($key->allowsOrigin('https://shop.example'));
        $this->assertFalse($key->allowsOrigin('http://shop.example'));
    }

    public function test_a_port_is_part_of_the_comparison(): void
    {
        // A dev entry on :3000 must not silently authorize the same host on its default port, or a
        // local allowlist entry would open production.
        $key = $this->keyAllowing(['http://localhost:3000']);

        $this->assertTrue($key->allowsOrigin('http://localhost:3000'));
        $this->assertTrue($key->allowsOrigin('http://localhost:3000/embed'));
        $this->assertFalse($key->allowsOrigin('http://localhost'));
        $this->assertFalse($key->allowsOrigin('http://localhost:3001'));
    }

    public function test_a_trailing_slash_on_either_side_is_normalised_away(): void
    {
        // Operators paste origins out of a browser bar; a trailing slash must not silently break a key.
        $this->assertTrue($this->keyAllowing(['https://shop.example/'])->allowsOrigin('https://shop.example'));
        $this->assertTrue($this->keyAllowing(['https://shop.example'])->allowsOrigin('https://shop.example/'));
    }

    public function test_a_null_origin_is_refused_when_an_allowlist_exists(): void
    {
        // A request with neither Origin nor Referer cannot be shown to come from an allowed page, so
        // it must fail closed.
        $this->assertFalse($this->keyAllowing(['https://shop.example'])->allowsOrigin(null));
    }

    public function test_an_empty_allowlist_is_deliberately_open(): void
    {
        // The documented posture: an unrestricted key. Asserted so nobody "fixes" it into a deny —
        // and, equally, so nobody inverts a future deny-by-default without noticing this contract.
        $this->assertTrue($this->keyAllowing([])->allowsOrigin('https://anywhere.example'));
        $this->assertTrue($this->keyAllowing([])->allowsOrigin(null));
    }

    public function test_an_unset_allowlist_behaves_like_an_empty_one(): void
    {
        // A row written before the column had a default carries null, not []. Reaching `foreach (null)`
        // here would be a fatal on a public endpoint.
        $this->assertTrue((new EmbedKey)->allowsOrigin('https://anywhere.example'));
    }
}
