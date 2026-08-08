<?php

/*
 * This file is part of linkrobins/link-gate.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\LinkGate\Tests\unit;

use Flarum\Testing\unit\TestCase;
use LinkRobins\LinkGate\DomainMatcher;
use PHPUnit\Framework\Attributes\Test;

class DomainMatcherTest extends TestCase
{
    private function matcher(string ...$domains): DomainMatcher
    {
        return new DomainMatcher($domains);
    }

    /** @test */
    #[Test]
    public function whatever_the_admin_typed_reduces_to_a_hostname(): void
    {
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('mega.nz'));
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('MEGA.NZ'));
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('  mega.nz  '));
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('https://mega.nz'));
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('https://mega.nz/folder/abc'));
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('mega.nz:443'));
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('mega.nz.'));

        // www is dropped so that one entry covers the bare host and the www one.
        $this->assertEquals('mega.nz', DomainMatcher::normaliseDomain('www.mega.nz'));
    }

    /** @test */
    #[Test]
    public function a_domain_with_no_dot_is_rejected(): void
    {
        // "localhost", or a half-typed entry, would otherwise match a huge
        // amount of unrelated content.
        $this->assertEquals('', DomainMatcher::normaliseDomain('localhost'));
        $this->assertEquals('', DomainMatcher::normaliseDomain('mega'));
        $this->assertEquals('', DomainMatcher::normaliseDomain(''));
        $this->assertTrue($this->matcher('localhost')->isEmpty());
    }

    /** @test */
    #[Test]
    public function the_host_and_its_subdomains_match(): void
    {
        $matcher = $this->matcher('mega.nz');

        $this->assertTrue($matcher->matches('https://mega.nz/folder/abc'));
        $this->assertTrue($matcher->matches('http://mega.nz'));
        $this->assertTrue($matcher->matches('https://www.mega.nz/x'));
        $this->assertTrue($matcher->matches('https://cdn.files.mega.nz/x'));
        $this->assertTrue($matcher->matches('HTTPS://MEGA.NZ/X'));

        // Written without a scheme, which is how an author often types it and
        // how TextFormatter still autolinks it.
        $this->assertTrue($matcher->matches('mega.nz/folder/abc'));
    }

    /** @test */
    #[Test]
    public function a_lookalike_host_does_not_match(): void
    {
        $matcher = $this->matcher('mega.nz');

        // The whole point of matching on the parsed host: these all contain the
        // domain as a substring but none of them is the domain.
        $this->assertFalse($matcher->matches('https://mega.nz.evil.com/x'));
        $this->assertFalse($matcher->matches('https://notmega.nz/x'));
        $this->assertFalse($matcher->matches('https://example.com/mega.nz'));
    }

    /** @test */
    #[Test]
    public function credentials_cannot_be_used_to_dodge_the_gate(): void
    {
        $matcher = $this->matcher('mega.nz');

        // The host is what counts, not the userinfo in front of it.
        $this->assertTrue($matcher->matches('https://someone@mega.nz/x'));
        $this->assertFalse($matcher->matches('https://mega.nz@evil.com/x'));
    }

    /** @test */
    #[Test]
    public function an_unparseable_value_containing_the_domain_is_gated_anyway(): void
    {
        $matcher = $this->matcher('mega.nz');

        // Nothing here yields a host, so the matcher falls back to asking
        // whether the domain is present at all. Over-gating is the safe way to
        // be wrong when the thing behind the gate is being sold.
        $this->assertTrue($matcher->matches('h**ps://mega.nz/x'));
        $this->assertTrue($matcher->matches('mega.nz'));
        $this->assertFalse($matcher->matches('/relative/path'));
        $this->assertFalse($matcher->matches(''));
    }

    /** @test */
    #[Test]
    public function an_empty_domain_list_matches_nothing(): void
    {
        $matcher = $this->matcher();

        $this->assertTrue($matcher->isEmpty());
        $this->assertFalse($matcher->matches('https://mega.nz/x'));
    }

    /** @test */
    #[Test]
    public function several_domains_are_all_gated(): void
    {
        $matcher = $this->matcher('mega.nz', 'drive.google.com');

        $this->assertTrue($matcher->matches('https://mega.nz/a'));
        $this->assertTrue($matcher->matches('https://drive.google.com/b'));
        $this->assertFalse($matcher->matches('https://google.com/b'));
    }
}
