# Themes

Segara ships with six server-rendered themes, selectable from **Admin →
Settings**:

| Theme | Name | Sidebars | Character |
|---|---|---|---|
| `clarity` | Clarity Canvas | 0 | Single column, minimal |
| `aubergine` | Aubergine Atelier | 1 | One sidebar for secondary content |
| `editorial` | Warm Editorial | 2 | Two sidebars for a magazine layout |
| `scholar` | Warm Scholarly Modernism | 1 | Warm paper, espresso ink, terracotta accent; one reading rail |
| `folio` | Warm Editorial Folio | 1 | Warm Editorial palette with one rail on the right and the dashboard's old-style serif throughout |
| `slate` | Slate Bootstrap | 0 | Compact menu-only bar (no brand: the site name lives in the band), full-bleed blue gradient jumbotron carrying the name, tagline and description, card content, dark footer. Loads Bootstrap 5.3.3 from jsDelivr (SRI-pinned); `style-src` is widened to that one path |

Each theme declares its own `--header-*` and `--footer-*` tokens, so the
navigation bar and the footer band take the theme's own surface rather than a
borrowed one.

Breakpoints: `clarity` 760 px, `aubergine` 1024 px, `editorial` 1280 px,
`scholar` 1200 px (desktop above 1200, stacked below), `folio` 1180 px (one
rail needs less room than editorial's two), `slate` 992 px (Bootstrap's
`navbar-expand-lg`).

A theme may set `$showBrand = false` before including `themes/shared/header.php` to keep the site
name out of the bar; `slate` does this and renders the name in its home-page band instead. A theme
with neither a brand nor a menu renders no bar at all.

The stored tagline is rendered in the footer, above the copyright line, in every theme. A theme that
shows it elsewhere opts out by setting `$showFooterTagline = false` before including
`themes/shared/footer.php`; `slate` does, because its band carries the tagline.

`themes/shared/page-date.php` renders the publication date at the foot of an
article from the `page_date` array the renderer builds. Every theme styles it
with the same `.page-meta` rule, which uses `--on-surface` at 72% opacity rather
than `--muted`: `--muted` measures 4.47:1 on scholar's canvas and would fail AA
at 13px. A new theme must copy that rule with its own tokens.

The selected theme applies to the home page, content pages, and error pages.
The theme allowlist is fixed in code (`src/View/Themes.php`); an unknown theme
id is rejected, never used for file lookup.

## Layout

Each theme provides:

- `theme.php` — descriptor (`id`, `name`, sidebar count)
- `layout.php` — the HTML shell (header, navigation, footer, sidebars)
- `home.php`, `page.php`, `error.php` — the three page types
- `assets/theme.css` — theme stylesheet, served through `theme.css.php?theme=…`

Shared partials (header, announcement, footer, hero) live in `themes/shared/`
and are used by every theme, so themes stay small and consistent.

## Adding a theme

1. Create `themes/<name>/` with the files above (copy a theme with the same
   sidebar count).
2. Set the descriptor sidebar count (0, 1, or 2).
2b. Declare `--content-max` and `--content-pad` in `:root`. Both `.layout` and
   `.header-inner` read them, so the header bar and the article share one
   column; a theme that omits them renders a header that is not aligned with
   its own text. `themes/shared/header.php` provides the `.header-inner`
   wrapper, so the theme only styles it.
3. Add `<name>` to the `ALL` constant in `src/View/Themes.php`. The Settings
   picker, the renderer fallback and `theme.css.php` all read that constant.
4. Re-run the test suite. Several tests loop a theme list, so a theme missing
   from one of them is either not exercised or fails loudly:
   `ThemeTest` (content, metadata, sidebar count, local assets only),
   `PublicNavigationTest` (bar surface, right-aligned menu, hover affordance),
   `FooterBlocksTest` (band, contrast ratios), `BlockLibraryTest` (ten block
   types), `PublicSidebarTest` (grid tracks only when a sidebar renders), and
   `SettingsTest` (card count). A theme with tokens of its own should also get a
   dedicated test file, as `tests/ScholarThemeTest.php` does for `scholar`.

Themes are read-only at runtime: no uploaded, executable, or dynamically
generated template is ever loaded.
