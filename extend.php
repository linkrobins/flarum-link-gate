<?php

use Flarum\Api\Serializer\BasicPostSerializer;
use Flarum\Extend;
use LinkRobins\LinkGate\Api\GateContentHtml;
use LinkRobins\LinkGate\Formatter\FilterGatedLinks;
use LinkRobins\LinkGate\Settings;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    // Stage one, and the only part that matters for the guarantee: the gated
    // URLs come out of the post's XML before it is rendered, for any reader
    // without the permission. Nothing downstream can put them back.
    //
    // Identical to the 2.x line: Formatter::render passes the request to every
    // rendering callback with the same signature on both majors.
    (new Extend\Formatter())
        ->render(FilterGatedLinks::class),

    // Stage two, cosmetic: the markers stage one left behind become the admin's
    // HTML. This is the extender that differs from the 2.x line, which reaches
    // the same swap through an API resource field instead.
    (new Extend\ApiSerializer(BasicPostSerializer::class))
        ->attributes(GateContentHtml::class),

    (new Extend\Settings())
        // A kill switch, so the behaviour can be neutralised without disabling
        // the extension and losing the domain list with it.
        ->default(Settings::ENABLED, true)
        ->default(Settings::DOMAINS, '')
        ->default(Settings::HTML, '')
        ->default(Settings::FALLBACK, '')

        // Declared from the first release so that per-domain messages can ship
        // by writing this key, with nothing to migrate. Empty means the four
        // settings above compose the single rule.
        ->default(Settings::RULES, ''),
];
