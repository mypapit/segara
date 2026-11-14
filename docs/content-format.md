# Content format

## Pages

A page has:

- **Title** (required, ≤200 chars) and a **slug** derived from it (or explicit).
- **Body** in limited Markdown (see below).
- **Description** (≤500 chars) for metadata, listings, RSS, and `llms.txt`.
- **State**: `published`, `draft`, `private` (password-protected), or `trash`.
  Draft and trash return 404; private pages show a password challenge.
- **Schema type** (`WebPage`, `Article`, etc.) and **placement**
  (`none`, `home`, `featured`).
- **Author** name, **tags**, and up to three **buttons**.
- Optional **video path** (local file path) and **featured media**.
- Optional **contact form** toggle.

## Markdown

Markdown is rendered with Parsedown in safe mode, with raw HTML escaped and the
result passed through an HTML sanitizer. Use standard Markdown — headings,
emphasis, links, lists, code spans, blockquotes. Arbitrary HTML and JavaScript
are not permitted and are stripped or escaped on output.

## Buttons

Each button has a fixed icon (from the built-in set), a label, and a target of
one of four types:

- `menu` — a configured menu
- `media` — a media item
- `document` — a local document path
- `custom` — a local or HTTPS URL

## Local media

The media library accepts **JPEG, PNG, and WebP**, up to **5 MiB**, uploaded in
the admin. Images are served through `media.php?id=…`.

**Video and document files are not uploaded.** They are deployed separately to
the server (e.g. `documents/` or `videos/` beside the document root) and
referenced by local path. The web server is configured to never execute PHP
under those directories.

## Feeds and machine-readable output

- `/rss.xml` — the newest 20 published pages, with featured-media enclosures.
- `/sitemap.xml` — canonical published URLs plus the home page.
- `/robots.txt` — disallows admin paths and points to the sitemap.
- `/llms.txt` — a Markdown index of published pages for LLM discovery.

Regenerate the sitemap/robots/llms after bulk changes with
`php bin/rebuild-public-files`.

### The browser view of the XML

`sitemap.xml` and `rss.xml` both declare a stylesheet (`sitemap-styles.xml`,
`rss-styles.xml`), so a browser shows a readable list of URLs or feed items
instead of raw angle brackets. Both load `assets/xml.css` through a `<link>`
rather than inlining styles, so the pages survive a future `style-src 'self'`
Content-Security-Policy.

The declaration is inert in Chromium 138 and later, which removed its XSLT
engine; Chromium falls back to its own XML tree view. Firefox and Safari apply
it. The stylesheets are plain XSLT 1.0 with no `document()` calls, and the feed
stylesheet deliberately omits the item description, which is rendered markup and
would otherwise print as raw tags.

## Publication dates

Each page shows its publication date under the article, inside a
`<time datetime>` element so the machine-readable date survives even when the
visible text is a relative phrase. Settings controls it:

| Option | Output |
|---|---|
| `full` (default) | October 25, 2026 (Sunday) |
| `plain` | October 25, 2026 |
| `custom` | any PHP date pattern, e.g. `j F Y` |
| `relative` | 2 days ago |

The pattern field accepts letters, digits, spaces and `, . / : - ( )`, up to 64
characters, and must contain at least one of `Y y F m M n j d` (a year, a month
or a day). It is applied in the timezone set in Settings, not in UTC.

The date comes from `published_at`, falling back to `created_at` for a page
published before that column existed. A stored pattern that fails the guard is
ignored at render time and the default format is shown, so a bad setting cannot
break a public page.
