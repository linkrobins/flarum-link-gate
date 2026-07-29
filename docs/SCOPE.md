# Link Gate: build scope

Status: scoped, not built. Written 2026-07-28.

Origin: an extension request. The requester runs a membership forum and wants file-host
links (mega.nz, drive.google.com and similar) to be readable only by people in his paid
members group. Everyone else, guests and ordinary registered users alike, should see a
block of his own HTML in the link's place, pitching the membership.

## 1. What it does

An admin picks a list of domains. When a post contains a link to one of those domains:

- a user **with** the `linkrobins-link-gate.viewGatedLinks` permission sees the post
  exactly as written, link intact;
- everyone else sees the admin's own HTML block where the link used to be, and the real
  URL is **not present anywhere in the response**.

Everything else in the post renders normally. A post can contain several gated links and
each one is replaced independently.

### Why the URL absence matters more than the visual

This is the whole engineering problem. A CSS or JavaScript implementation that merely
hides the anchor still ships the URL inside the JSON at `/api/discussions/123`, so anyone
can open devtools or curl the endpoint and read it. For a paid membership that is not a
paywall, it is a speed bump. Link Gate removes the URL **server side, before render**, so
the byte never leaves the server for an unauthorised actor.

### Prior art

The closest existing extension is [`datlechin/flarum-bbcode-hide-content`], which hides
content the author explicitly wraps in a `[hide]` BBCode tag. That is a different product:
it is author-driven and per-post, requires editing every existing post, and gates on
"has replied" rather than on a group. Nothing on Packagist does automatic
domain-matched, permission-gated link replacement.

[`datlechin/flarum-bbcode-hide-content`]: https://packagist.org/packages/datlechin/flarum-bbcode-hide-content

## 2. Architecture

### 2.1 The hook (verified on both majors)

Flarum stores post content as TextFormatter XML and renders it to HTML on **every
request**, with the request in hand. There is no stored or cached HTML, which is what
makes per-viewer rendering possible at all.

`Flarum\Formatter\Formatter::render()` runs every registered rendering callback over the
XML before handing it to the renderer, and passes the request through:

| | 2.0 | 1.8 |
|---|---|---|
| `Formatter::render($xml, $context, $request)` | `core/src/Formatter/Formatter.php:84` | `core/src/Formatter/Formatter.php:116` |
| callback signature | `($renderer, $context, $xml, $request)` | `($renderer, $context, $xml, $request)` |
| `Extend\Formatter->render()` | `core/src/Extend/Formatter.php:104` | `core/src/Extend/Formatter.php:108` |
| `RequestUtil::getActor($request)` | `core/src/Http/RequestUtil.php:91` | `core/src/Http/RequestUtil.php:17` |

The signatures are **identical across majors**, so the security-critical code is a single
code path with no version branching. That is the main reason dual-major support is cheap
here, unlike Discussion Banners.

### 2.2 The two-stage replacement

The admin's replacement is HTML, and TextFormatter escapes text and forbids
`disable-output-escaping` in templates, so raw HTML cannot be injected at the XML stage.
Splitting it in two solves that without weakening the guarantee:

**Stage 1, in the rendering callback (shared, both majors).**
Walk the XML with `DOMDocument`. For every element, test every attribute that parses as a
URL against the domain list, so `<URL url>`, `<IMG src>` and any tag an embed extension
added are all covered rather than just anchors. On a match, when the actor lacks the
permission, replace the whole element with a sentinel text node. Autolinked URLs carry the
address in their **text content** as well as the `url` attribute, so replacing the element
outright is required; rewriting the attribute alone would leave the URL visible as text.

Sentinel: a private-use codepoint pair, e.g. `\u{E000}LRLG:0\u{E000}`. Any occurrence of
the sentinel in user-authored content is stripped first, so nobody can forge a members-only
block by typing one.

Fast path: `Utils::getAttributeValues($xml, 'URL', 'url')` first, and skip the DOM parse
entirely when no candidate is present. Most posts contain no gated link and should cost
close to nothing.

**Stage 2, on the `contentHtml` field.**
After render, swap the sentinel for the admin's HTML. This is the one place needing a
per-major shim:

- 2.0: `Extend\ApiResource(PostResource)` overriding the `contentHtml` field
  (`core/src/Api/Resource/PostResource.php:206`).
- 1.8: `Extend\ApiSerializer(BasicPostSerializer)` attribute mutator
  (`core/src/Api/Serializer/BasicPostSerializer.php:63`).

The admin HTML is sanitised server side before insertion. Admins can already inject HTML
via Custom Header, so this is defence in depth rather than a trust boundary, but it keeps
a compromised admin session from turning into stored XSS in every post.

### 2.3 Permission, not a group ID

The requester asked for "a specific group". Implement it as a Flarum permission,
`linkrobins-link-gate.viewGatedLinks`, seeded to no group by default and assigned from the
standard Permissions page. Same outcome for him, and it lets any admin grant it to several
groups, or to moderators, without touching settings. Storing a raw group ID would also
break silently if the group were ever deleted and recreated.

## 3. Settings

| Setting | Type | Notes |
|---|---|---|
| Enabled | boolean, default on | Kill switch, house standard, so behaviour can be neutralised without disabling the extension |
| Gated domains | textarea, one per line | `mega.nz` matches the host and all subdomains. Empty list = extension does nothing |
| Replacement HTML | textarea | What non-permitted users see. Sanitised server side |
| Plain-text fallback | text | Used in emails and anywhere HTML is not appropriate. Translatable default |

One replacement message globally in v1. Per-domain messages are the obvious v1.1, and the
storage should be a JSON rule list from the start so that upgrade needs no migration
(Discussion Banners had to write one; learn from it).

Admin UI is typed settings only, which both majors' auto-built extension pages render
without a custom component.

## 4. Leak surface

This is where the hours actually go. Each row is a real path that content takes out of the
server, checked against core and the bundled extensions.

| Path | Status | Notes |
|---|---|---|
| `GET /api/discussions/:id`, `/api/posts` | **Covered** | Everything goes through `PostResource::contentHtml` → `formatContent($context->request)` |
| Server-rendered discussion page | **Covered** | `core/views/frontend/content/discussion.blade.php:10` prints `contentHtml` from the same API document |
| Post edit form, raw source | **Covered by core already** | `PostResource.php:183` gates the `content` field on `can('edit', $post)`, so only the author and moderators ever receive the source. No work needed |
| Mentions preview tooltip, post search results | **Covered** | Both fetch posts through the same resource |
| **Email notifications** | **Needs a decision, see below** | `subscriptions/views/emails/html/newPost.blade.php:11`, `mentions/.../postMentioned.blade.php:12`, `userMentioned.blade.php:11`, `groupMentioned.blade.php:11` and `messages/.../messageReceived.blade.php:14` all call `formatContent()` with **no request** |
| Search by URL text | **Partial, by design** | Default search runs `LIKE` over `posts.content`, the XML, which contains the URL. A non-member searching `mega.nz` can learn *which* posts contain such a link. They never receive the URL itself, only its existence |
| Full-page cache or CDN | **Deployment note** | Post HTML now varies per viewer. Any full-page cache must vary by user or be off. Goes in the README |
| Third-party post-history extensions | **Documented caveat** | An extension that surfaces raw revisions bypasses `contentHtml` entirely. Cannot be fixed from here |

### The email decision

Those five blades render with `$request = null`, so the callback cannot tell who the mail
is for. Options:

1. **Fail closed** (recommended): null request means not permitted, so every notification
   email shows the plain-text fallback instead of the link. Members lose the link in email
   and have to open the discussion. No leak.
2. Fail open: emails keep the link, which leaks it to every subscriber regardless of
   group. Not acceptable for a paywall.
3. Resolve the recipient properly by extending the notification mailer. Real work, and it
   reaches into two bundled extensions. Not v1.

Go with 1, and say so plainly in the README, because "why does the email not show the
link" is otherwise a support ticket.

## 5. Build plan

| # | Milestone | Est. |
|---|---|---|
| 1 | Scaffold: composer, extend.php, locale, plain-webpack dual-major bundle, CI, phpstan | 0.5 d |
| 2 | Domain matcher + XML filter + sentinel, with unit tests over hand-built XML | 1 d |
| 3 | `contentHtml` shims for both majors, permission registration, settings | 0.5 d |
| 4 | Admin page, 4 typed settings, both majors | 0.5 d |
| 5 | Leak-surface hardening: email fallback, sentinel forgery, sanitiser | 0.5 d |
| 6 | Tests: unit on the matcher, integration asserting the URL is absent from the payload for a non-permitted actor and present for a permitted one | 0.5 d |
| 7 | Bench verification on both majors, 2.0 at `:8890` and 1.8 at `:8891`, README, release | 0.5 d |

Roughly **4 days**. The feature the requester describes is milestones 2 to 4, about two
days. The other two days are the leak surface and dual-major verification, which is the
part that makes it an actual paywall rather than a visual trick.

### The must-pass test

An integration test that posts a gated link, fetches the discussion as a non-permitted
actor, and asserts the raw URL string appears **nowhere** in the serialised response body.
That single assertion is the product.

## 6. Open questions for the requester

1. Should the **post author** keep seeing their own link when they lack the permission?
   Core already sends them the raw source in the edit field, so hiding it in the rendered
   post is cosmetic. Recommend: author and moderators always see it.
2. Domain matching only, or path patterns like `mega.nz/folder/*`?
3. Should gated links inside `<code>` blocks and quotes be replaced too? Recommend yes for
   code, since a URL in a code block is still a URL.
4. What should the fallback text be in notification emails, given section 4?
5. Which Flarum version is his forum on? Does not change the build, but decides which
   bench gets verified first.

## 7. Explicitly out of scope for v1

- Per-domain replacement messages (v1.1, storage designed for it now)
- A WYSIWYG editor for the replacement HTML. It is a textarea. He asked for HTML, and the
  afrux/news-widget screenshot he sent is a plain HTML field, not a rich editor
- Gating anything other than post content: user bios, discussion titles, PM bodies
- Click tracking or "N members viewed this link" analytics
- Rewriting links to a redirect or interstitial page rather than removing them
