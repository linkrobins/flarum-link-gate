<?php

namespace LinkRobins\LinkGate;

/**
 * Decides whether a URL points at one of the admin's gated domains.
 *
 * Every ambiguity here resolves towards gating. This runs behind a paywall, so
 * gating a link that did not need it is a cosmetic annoyance, while missing one
 * hands out the thing the admin is selling.
 */
class DomainMatcher
{
    /** @var list<string> lowercased, bare hostnames */
    private array $domains;

    /**
     * @param list<string> $domains
     */
    public function __construct(array $domains)
    {
        $this->domains = array_values(array_filter(array_map(
            [self::class, 'normaliseDomain'],
            $domains
        )));
    }

    public function isEmpty(): bool
    {
        return $this->domains === [];
    }

    /**
     * The bare hostnames this matcher gates, for cheap substring screening.
     *
     * @return list<string>
     */
    public function domainList(): array
    {
        return $this->domains;
    }

    /**
     * Reduce whatever the admin typed to a bare hostname.
     *
     * Accepts "https://mega.nz/folder", "mega.nz/", "MEGA.NZ" and "www.mega.nz"
     * alike. The leading "www." is dropped so that the subdomain rule below
     * covers both the bare host and the www one, which is what an admin who
     * typed either of them meant.
     */
    public static function normaliseDomain(string $domain): string
    {
        $domain = trim($domain);

        if ($domain === '') {
            return '';
        }

        // Strip a scheme, then anything from the first path, query or fragment.
        $domain = preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $domain) ?? $domain;
        $domain = preg_split('~[/?#]~', $domain)[0];

        // Strip credentials and a port.
        if (($at = strrpos($domain, '@')) !== false) {
            $domain = substr($domain, $at + 1);
        }

        $domain = preg_replace('~:\d+$~', '', $domain) ?? $domain;
        $domain = strtolower(rtrim(trim($domain), '.'));

        if (str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        // A bare label with no dot ("localhost", or a typo) would match far too
        // much, so it is rejected rather than trusted.
        return str_contains($domain, '.') ? $domain : '';
    }

    /**
     * Whether this value is a link to a gated domain.
     */
    public function matches(string $value): bool
    {
        if ($this->domains === []) {
            return false;
        }

        $host = $this->host($value);

        if ($host === null) {
            // No host could be read, so fall back to asking whether the domain
            // appears at all. Over-gates on a URL that merely mentions the
            // domain in a query string, which is the safe direction to err.
            $haystack = strtolower($value);

            foreach ($this->domains as $domain) {
                if (str_contains($haystack, $domain)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->hostForms($host) as $form) {
            foreach ($this->domains as $domain) {
                if ($form === $domain || str_ends_with($form, '.'.$domain)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Read the hostname out of a URL, or null if there is not one to read.
     */
    private function host(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // A value with no scheme is still a link as far as an author is
        // concerned ("mega.nz/folder/abc"), and TextFormatter autolinks it, so
        // it is parsed as though it had one.
        $subject = preg_match('~^[a-z][a-z0-9+.\-]*:~i', $value) ? $value : '//'.$value;

        $host = parse_url($subject, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        // parse_url will hand back anything up to the next slash, so a mangled
        // address like "h**ps://mega.nz/x" yields the host "h**ps". Trusting
        // that would let an author dodge the gate by breaking their own scheme,
        // and the reader still reads the URL fine. Anything that is not
        // hostname-shaped is treated as unparseable so the caller falls back to
        // looking for the domain anywhere in the value.
        if (! preg_match('~^[a-z0-9\x80-\xff]([a-z0-9\x80-\xff.\-]*[a-z0-9\x80-\xff])?$~', $host)
            || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    /**
     * The forms of a host worth comparing: as written, and punycoded.
     *
     * An internationalised domain reaches us either way round depending on how
     * the author typed it, and the admin will have configured only one of them.
     *
     * @return list<string>
     */
    private function hostForms(string $host): array
    {
        $forms = [$host];

        if (preg_match('~[^\x20-\x7e]~', $host) && function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '' && $ascii !== $host) {
                $forms[] = strtolower($ascii);
            }
        }

        return $forms;
    }
}
