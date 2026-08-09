# Link Gate for Flarum

[![Latest Stable Version](https://img.shields.io/packagist/v/linkrobins/link-gate.svg)](https://packagist.org/packages/linkrobins/link-gate)

Pick the domains you want to keep behind a permission. Anyone without it sees
your own message where the link used to be, and **the address is not in the
page at all**.

## The part that matters

Plenty of setups hide a link with CSS or a bit of JavaScript. That looks right
in the browser and is not a gate: the address is still sitting in the API
response, so anyone can open developer tools, or fetch
`/api/discussions/123` directly, and read it. If you are charging for access,
that is a speed bump.

Link Gate removes the address on the server, before the post is rendered. For a
reader without the permission the bytes never leave the machine, so there is
nothing to find in devtools, in the page source, or in the API.

## Setup

```sh
composer require linkrobins/link-gate
```

Then enable it and, on the extension's settings page:

1. **Gated domains**, one per line, for example `mega.nz`. Subdomains are
   included, so `mega.nz` also covers `folder.mega.nz`. Leave it empty and
   nothing is gated.
2. **What to show instead**: the HTML shown where a gated link used to be.
3. **Plain wording**: a short line used where HTML cannot go, mainly in
   notification emails.

If your forum runs in more than one language, an **Other languages** section
appears under those settings, with a box per installed language. Fill one in and
readers using it see that instead; leave it blank and they get what you wrote
above.

Finally, on **Permissions**, grant *View gated links* to the groups that should
see the real links. Nobody has it by default. It is a permission rather than a
single group setting, so you can give it to several groups at once and it
survives a group being renamed or recreated.

## Who sees what

| Reader | Sees |
| --- | --- |
| Has *View gated links* | The post exactly as written |
| Everyone else, members and guests alike | Your message in the link's place |
| The person who posted it, and moderators | The post as written |

That last row is deliberate. Flarum already sends the raw text of a post to
anyone who is allowed to edit it, which is how the edit box gets filled in, so
hiding the rendered link from the author would not actually hide anything. It
would just confuse the person who posted it.

## Things worth knowing before you rely on it

**Notification emails do not contain gated links.** When Flarum builds a
notification email there is no reader attached to ask about, so Link Gate has
nobody to check and refuses rather than guesses. Subscribers get your plain
wording instead of the link and open the discussion to get it. Guessing the
other way would mail the address to every subscriber regardless of their group,
which would undo the whole thing.

This covers both halves of the message. A notification email is sent as HTML
with a plain-text copy alongside it, and the plain copy is built from the post's
own source rather than from the rendered version, so it needs stopping
separately. Mail clients show the HTML, but the plain copy travels with it and
is just as readable.

**Searching can reveal that a link exists.** Flarum's default search looks
through the stored text of posts, so someone searching `mega.nz` may find that
a post mentions it. They never receive the address itself, only the fact that
it is there.

**Full-page caches have to vary by reader.** Post content is no longer the same
for everyone, so a cache or CDN in front of the forum must vary by user, or be
off for discussion pages. Flarum's own caching is fine.

**An extension that shows raw post history bypasses this.** Anything that
surfaces the stored source of a post rather than the rendered version is
outside what Link Gate can reach.

## Release lines

| Flarum | Branch | Versions |
| --- | --- | --- |
| 2.x | `main` | `2.y.z` |
| 1.8 | `1.x` | `1.y.z` |

Composer picks the right one for your forum on its own, so
`composer require linkrobins/link-gate` is all you need either way.

## Links

- [Report a problem](https://github.com/linkrobins/flarum-link-gate/issues)
- [Forum and support](https://linkrobins.com/forum)
