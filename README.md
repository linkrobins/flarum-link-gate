# Link Robins Link Gate

Hide links to chosen domains behind a permission, and show everyone else your own message
in their place.

Pick the domains you want to protect. Anyone without the "view gated links" permission
sees your own HTML where the link used to be, and the real address is removed on the
server before the page is sent, so it is not in the page source and not in the API
response either. Anyone with the permission sees the post exactly as written.

Works on Flarum 1.8 and 2.0.

## Status

**Not built yet.** This repository currently holds the build scope only:
[`docs/SCOPE.md`](docs/SCOPE.md).

That document covers the architecture, the paths post content can leak out of and how each
one is handled, the settings, a milestone plan, and the questions still open with the
person who requested the extension. It is worth reading before the first line of code.

## License

MIT, see [LICENSE](LICENSE).
