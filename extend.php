<?php

use Flarum\Api\Resource\PostResource;
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
    (new Extend\Formatter())
        ->render(FilterGatedLinks::class),

    // Stage two, cosmetic: the markers stage one left behind become the admin's
    // HTML. This is the one extender that differs on the 1.8 line.
    (new Extend\ApiResource(PostResource::class))
        ->field('contentHtml', GateContentHtml::class),

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
