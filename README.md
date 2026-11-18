# Custom functionality plugin

This plugin is Composer-first, PSR-4 autoloaded, and structured around declarative content model definitions plus small registrar classes. It is used with Bedrock and provides the site's custom post types, taxonomies, headless admin behavior, and other sundries.

## Component Ingestion Contract

- Source of truth is the Git-pinned webcomponents source. Normal `npm run build` explicitly builds `node_modules/abcnorio-webcomponents`, then ingests its generated `dist`; the meta repo's `just plugin-dev-assets` builds the local sibling source instead for WordPress preview work.
- Plugin-owned runtime artifacts are copied to `resources/vendor/components/dist` during `npm run build`.
- This copied directory is gitignored, like `build/`; authored `resources/js`,
  `resources/css`, and static assets remain tracked. After cloning, install locked
  dependencies with `npm ci` and run `npm run build` before starting WordPress.
- Neither the component package's original `dist/` nor its plugin copy is tracked.
  `npm ci` installs source without building it; `npm run build:components` builds
  and ingests it explicitly (also included in `npm run build`). Missing build
  sources are an error, never a fallback to stale dist.
- `ABCNORIO_COMPONENTS_DIST_SOURCE=/absolute/path/to/abcnorio-webcomponents/dist`
  selects a local source checkout for builds; install that checkout's dependencies
  with `npm ci` first. The override's parent must be buildable component source.
- Runtime PHP reads fixtures/assets only from plugin-local path (`resources/vendor/components/dist`).
- Runtime CSS is enqueued as a static plugin URL from that same plugin-local path.
- Contract is fail-loud: missing dist/manifest/css is a hard error.

## Release Artifact Contract

- `build/` (webpack-compiled editor script) and `resources/vendor/components/dist/`
  (ingested component assets) are gitignored. Release archives contain both.
- Staging installs this plugin via Composer's `artifact` repository type (a folder of versioned tarballs), not a plain VCS checkout, so `build/` still reaches runtime without ever being tracked in git.
- Release tarballs are produced by `abcnorio-meta/scripts/build-plugin-release.sh` (invoke via `just plugin-artifact [tag]` from `abcnorio-meta/`): it exports the exact git tag via `git archive` into a clean scratch directory, runs `npm ci && npm run build` there, and packages the runtime footprint (`composer.json`, `custom-func.php`, `src/`, `resources/`, `build/`) into `abcnorio-meta/wp/plugin-artifacts/abcnorio-func-<version>.tar.gz`.
- From `abcnorio-meta`, `just plugin-dev-assets` builds local webcomponents and rebuilds the mounted dev plugin preview assets. It does not bump versions, commit, tag, push, or deploy.
- `just plugin-release staging <bump>` builds and validates one candidate artifact from clean `HEAD` before changing the source version, committing, tagging, or pushing. It builds the locked webcomponents source and validates its manifest in scratch, without rebuilding the live sibling `dist/` tree.
- Rebuild an old release with `just plugin-artifact vX.Y.Z`; the script exports that tag and `npm ci` uses the exact dependency commit recorded in its `package-lock.json`.
- Keep the tagged `package-lock.json` as the dependency source of truth; `npm install` can refresh the `#main` Git reference and should not be used for historical artifact rebuilds.

### Component dep enqueue

- `manifest.components[name].deps` is a flat array of relative dist paths for transitive CSS/JS deps declared in fixture metadata.
- `ComponentIngestor::enqueue_component_deps(string $component_name)` infers asset type from file extension and enqueues under namespaced handles: `abcnorio-dep-{type}-{component}-{slug}-{hash}`.
- Handle is deterministic per path; WordPress deduplicates re-enqueues automatically.
- Block renderers explicitly enqueue their declared component dependencies; reading
  a fixture through `readDistHtml()` does not enqueue assets.
- Direct-query blocks (`EventListingQuery`, `ContentListingQuery`) call `enqueue_component_deps` explicitly for each component they render, including child teasers.

1. Install dependencies in `abcnorio-func`.
2. Run `npm run build` in `abcnorio-func`.

## Admin CSS Contract

- Component-specific styles, including editor previews, belong in the webcomponents component stylesheets, not `admin-overrides.css`.
- Gallery's "Captioned Gallery" variant uses slider layout with core/image captions beneath images and no lightbox.
- Components own optional `editor.scss` files, compiled separately and listed under `manifest.components[name].editorCss`. The plugin enqueues them only in the block editor, after shared fixture styles. Gallery editor sizing and navigation previews live in its `editor.scss`.
- Admin CSS is direct: `ComponentIngestor::enqueueRuntimeStyles()` plus `resources/css/admin-overrides.css`.
- Block editors also load packaged `styles/component-fixtures.css` and each component's manifest-declared `editorCss` through `enqueue_block_assets`, including the editor iframe. Repeated hooks reuse registered styles. These styles are available for newly inserted blocks, not just saved content.
- Compiled component scripts under `elements/` load as ES modules. Event listings use the matching grid/slider fixture, which owns the navigation buttons and view-all link; PHP fills in content without rewriting those elements. Their styles no longer require Astro scope attributes.
- Run event template regression checks in a WordPress installation with `wp eval-file /path/to/abcnorio-func/tests/event-listing-render.php`.
- `resources/css/admin-overrides.css` stays manual and small.
- Admin overrides cover editor-shell typography/layout, link click suppression, and admin UI only. Content buttons use component styles; WordPress buttons and block appenders retain native styling.
- No generated `resources/css/admin-styles.css` is part of the runtime contract.
- Empty listing override stylesheets and their content-scanning enqueue hooks have
  been removed. Listing styles come from the component manifest for both saved
  blocks and newly inserted editor previews.

### Local WordPress development

Install host Node dependencies in this repository and the sibling
`abcnorio-webcomponents` checkout (`npm ci` in each), and install
[Watchexec](https://github.com/watchexec/watchexec) (`brew install watchexec` on macOS).
From `abcnorio-meta`, run `just dev-local` and keep it running.

This one foreground command prepares calendar assets and starts:
- The existing `wp-scripts start` watcher for plugin JS/imported CSS.
- A queued Watchexec job building sibling component source, validating it and
  publishing plugin-local component assets, initially and after source/config saves.

Wait for `[components] Ready`, then refresh WordPress. PHP and directly served
plugin CSS changes only require refresh. There is no automatic editor reload,
commit, dependency pin or deployment between edits. Astro's native source/HMR
workflow is unchanged and need not be running for WordPress component previews.
Ctrl-C stops both watchers. Build errors appear in the terminal; the component
watcher remains available for the next save, keeping the previous successful output.

Development publication writes a complete `resources/vendor/components/dev-<id>`
generation and atomically replaces `dev-current.json` only after validation.
WordPress local/development requests pin filesystem paths and asset URLs to one
generation. Old generations remain available to open editor tabs. They consume
disk space until explicitly cleaned; close editor tabs and stop watchers before
running `just plugin-dev-clean`. This returns development to the normal `dist`
until the next successful watched build. Normal/release builds always produce
`dist`; staging/production ignore development generations entirely.

Validate PHP loading with `php tests/component-styles.php` and
`php tests/component-generation.php`; run JS tests with
`node --experimental-vm-modules --test tests/*.test.mjs` from this repository.

### Hero editor

Hero has one editor presentation path: the component-owned `hero/editor.html`
fixture, rendered from the same Astro markup as the frontend. PHP embeds it
before the editor bundle from the request's active component generation.
There is no query flag, runtime fetch, alternate React shell or Shadow DOM.

The shared `componentShell.js` adapter turns the trusted generated shell into
React elements and replaces its image/content markers with Gutenberg controls
and InnerBlocks. It preserves `useBlockProps` on the root and applies the
block's background-image CSS variables through the declared `image` binding.
React still owns inspector/media controls, attributes and editable child blocks.
The save format stores only `InnerBlocks.Content`; presentation wrappers are no
longer persisted. Existing wrapper-based hero content requires the one-time
migration below. No legacy save/deprecation layer is registered.

The adapter deliberately accepts a limited set of shell tags and rejects inline
styles/event attributes. Missing fixtures/markers and unsupported markup are
errors, not fallbacks to duplicated markup. Update the adapter and its checks
when intentionally extending that contract. Component builds and the editor
bundle must be released together, as with other packaged fixture consumers.

Run `php tests/hero-shell.php` for shell loading in all environments and
`node --experimental-vm-modules --test tests/hero-block.test.mjs` for controls and
the child-only save contract.

### Shared editable shell contract

Hero, announcement tout, gallery, press item and the newsletter/public-form placeholders use the same
adapter/loading path. Each component's
`editor` fixture declares exactly one `data-editor-root`, uniquely named
`data-editor-bind="name"` elements and `__EDITOR_SLOT_NAME__` text-node regions.
These declarations are emitted only for editor fixtures, not frontend markup.
The plugin discovers `<component>/editor` fixtures through the active manifest
and embeds them together as `window.abcnorioEditorShells` before the editor JS.
Required component shells missing from an old package produce an explicit error.

React supplies `blockProps`, named `slots` and named `bindings`. Bindings may
supply `props`, `children`, `tag` and `hidden`; their HTML still comes from the
fixture. Missing/extra names and unsupported tags/inline style/event attributes
in fixture markup fail explicitly. Heading/content changes retain stable
InnerBlocks nodes; do not move DOM or inject markup over React-owned regions.
Editable bindings may supply `render({ tag, props, children })` to render
Gutenberg RichText using the component-declared tag/props instead of duplicating
its presentation element. Preserve the supplied React key.
Gallery adds scoped `<template data-editor-repeat="name">` repeaters. React
supplies keyed item bindings, not repeated navigation JSX. Item keys must be
unique strings/numbers; nested slots/bindings are checked in their own scope.
Custom-element classes are emitted as `class`, not React's `className` attribute.
Extend this small contract only for demonstrated requirements, not arbitrary HTML.

Announcement tout retains its inspector controls and child-only save, but no
longer duplicates toast/title/content HTML in its editor. Optional headings,
heading levels 1-6 and secondary styling are bound into the Astro shell. Existing
stored content requires no migration because its save format has not changed.

Browser regression checks use installed public WordPress scripts and an existing
Playwright-compatible browser. Generate a dependency URL list (no inline WP
settings or credentials) and run from this repository:

```sh
wp eval-file /path/to/abcnorio-func/tests/editor-script-dependencies.php > /tmp/abcnorio-editor-scripts.json
ABCNORIO_WP_SCRIPTS=/tmp/abcnorio-editor-scripts.json node tests/component-shell-browser.mjs
```

Set `ABCNORIO_TEST_BROWSER` to an existing Chromium-family executable if needed;
`ABCNORIO_COMPONENTS_DIST_DIR` can select an isolated built component directory.
The test uses synthetic content, never saves posts or uploads media, and closes
its browser afterward.

#### One-time hero content migration

Before deploying the child-only save format to an existing database, stop editor
writes and close editor tabs. Back up, migrate and release the matching editor
assets within the same maintenance window; do not expose old content to the new
save format or reopen stale editor tabs. No content recreation is required.

In a bootstrapped WordPress installation (add your usual WP CLI flags), run:

```sh
wp eval-file /path/to/abcnorio-func/tests/hero-content-migration.php
wp eval-file /path/to/abcnorio-func/tests/hero-content-migration-database.php
wp eval-file /path/to/abcnorio-func/scripts/migrate-hero-content.php
wp eval-file /path/to/abcnorio-func/scripts/migrate-hero-content.php backup /private/backup/hero-content.json
# Preserve a private copy outside ephemeral containers before applying.
wp eval-file /path/to/abcnorio-func/scripts/migrate-hero-content.php apply /private/backup/hero-content.json
wp eval-file /path/to/abcnorio-func/scripts/migrate-hero-content.php
```

The default is read-only. The inventory covers active placed instances, database
patterns/reusable blocks and templates. Revisions/autosaves, drafts/auto-drafts
and trash are excluded; private content remains in scope. It also checks
secondary metadata/options stores for hero markers.
It preserves attributes, children and unrelated markup, rejects unknown shapes or
unrelated serialization changes, and checks idempotence. Reports contain counts
and record IDs, not post content. Unexpected formats/secondary stores must be
reviewed before any application. Multisite requires a separate explicit plan.

Backups contain affected content before/after migration, are exclusively created
with private permissions outside WordPress directories, and are checked against
the current site's identity and transformation. Keep them private and out of Git.
Apply/restore require InnoDB, lock and compare each record, verify stored bytes,
and roll back the entire transaction on conflict/error. They update only
`post_content`, preserving IDs/statuses/attributes/timestamps without creating
legacy revisions or triggering ordinary post-save side effects. Post caches are
cleared and the frontend content-change signal is refreshed after success.
The database test uses a disposable, uniquely named isolated table and removes it.

Rollback, with matching old editor source/assets restored during maintenance:

```sh
wp eval-file /path/to/abcnorio-func/scripts/migrate-hero-content.php restore /private/backup/hero-content.json
```

Restore refuses to overwrite subsequent content edits. Repeated apply/restore
is a no-op when already at the target state. A successful migration leaves zero
legacy heroes; reopen the editor and verify nested edits, media/variants and no
validation errors. Old database restores or imports must be inventoried/migrated
before editing; they are not silently supported through a runtime fallback.
The migration scripts are maintenance tooling only, never automatic boot hooks.

### Gallery editor

- Group and gallery component styles load together. Gallery layout and editor CSS belong to the webcomponents package; admin overrides retain only WordPress-specific inspector adjustments.
- In the editor, gallery groups share one full-width frame, with fit-content, left-aligned headings. Outermost dark groups remain transparent; their descendants receive the dark background and light text.
- React retains ownership of image InnerBlocks. Slider previews scroll the existing editable track (including keyboard-selected images) rather than running the frontend carousel's DOM/lightbox setup.
- Paged-grid previews use the component's entering/leaving animations, respect reduced motion, and retain the tallest visited grid height until a resize or content/settings change.
- Navigation changes preview state only, not saved block attributes. Run editor behavior checks with `node --test tests/gallery-preview.test.mjs`.
- Astro owns the shell, slider dots, paged navigation and item wrapper template.
  PHP imports the matching variant fixture and clones its item wrapper; React
  binds the real `useInnerBlocksProps` track. The outer Gutenberg block wrapper
  and core/image editing/save markup remain WordPress-owned infrastructure.
- Navigation always steps by pages, matching the public component. The obsolete
  `navigationMode` attribute is ignored; existing child-only content needs no
  database migration. **Responsive slider counts are unchanged**: 2 at base/XS,
  3 at SM/MD and 4 at LG. `itemsPerPage` still controls paged grids only.
- Run `wp eval-file tests/gallery-shell.php` in the bootstrapped plugin environment
  for exact PHP/fixture shell parity, caption encoding and item population across
  all five variants. The shared browser regression also checks gallery variant
  changes, stable editable DOM, selected-image reveal, add/remove/reorder,
  template pagination and existing-content save/reload.

### Newsletter placeholder

The editor and PHP render the same component-owned inert placeholder, not a
reproduction of the selected public form. Header/subtitle/button/required-note
controls bind into `newsletter-signup/editor` and `newsletter-signup/preview`
fixtures. Field/group inspector controls and saved attributes remain intact.
List data still loads for those controls, with existing loading/error feedback;
the placeholder does not depend on that request succeeding.

The placeholder has no form, real inputs, submission endpoint or public runtime
initialization marker. Its button is disabled. Public Astro form/submission
behavior is unchanged, and the block still saves attributes only (`save: null`).
Run `node --experimental-vm-modules --test tests/newsletter-signup-block.test.mjs`
and `wp eval-file tests/newsletter-preview.php` in WordPress; the shared browser
test covers inertness during loading/success/error and preserved settings.

### Public form placeholder

The forms block keeps its configured ACF form selector and saved `formSlug`.
Its editor uses the component-owned `public-form/editor` shell to show the
selected title/slug and disabled submit-label button, not copied field/group
markup. Loading, no selection, no configured forms, missing saved form,
request errors and invalid responses have explicit status messages. A missing
or temporarily unavailable selection is not automatically cleared.

There are no form controls, submission endpoints or frontend form scripts in
the placeholder. Frontend FormShell/FieldControl and submission behavior remain
unchanged. The block still saves attributes only; no migration is needed.
Validate with `node --experimental-vm-modules --test tests/public-form-block.test.mjs`
and the shared Gutenberg browser regression (synthetic forms API, no submissions).

### Press item

The existing post type, page organization and saved content are unchanged.
`press-item/editor` owns the article/date/title/source shell. React binds
RichText directly to its declared heading/source elements and retains the date
and fallback-date controls. The editor imports the component package's date
formatter, including invalid-date fallback, instead of maintaining its own.
Source links are constructed through DOM text/attribute APIs before RichText
editing so literal text and quoted URLs are not interpolated into markup.
The block still saves attributes only; no migration is required.

The shared browser regression verifies real title/source editing, stable DOM,
link roundtrips, dates/fallbacks and valid attribute-only save/reload.

### Listing, teaser and sidebar markup ownership

These blocks retain their fixture-populated PHP/server-rendered previews.
Sidebar already shares its complete presentation; no new editor renderer is
necessary. Event/collective counts and event-teaser date spans now come from
component fixtures, not PHP-created elements. Card image population requires
the fixture's image region when an image is supplied; missing presentation
regions fail explicitly instead of creating replacement markup. Collective
teasers no longer contain unused copied event/date rendering logic.

Validate with WordPress `wp eval-file` for `tests/event-listing-render.php`,
`tests/collective-listing-render.php`, `tests/content-listing-render.php` and
`tests/listing-layout.php`. Query order, pagination, responsive slider settings
and existing core block save contracts remain unchanged.

### Core adapters and embeds

Core blocks keep their native Gutenberg editors and saved markup. They do not
need the shared editable-shell adapter: the component package already owns their
frontend rendering, and the plugin does not maintain parallel presentation JSX.

| Surface | Ownership retained |
| --- | --- |
| Group | Core editor/save; plugin layout hooks adjust only new/default group layouts and simple transforms. Shared Astro adapter owns frontend layout classes/children. |
| Buttons | Core buttons/button editing/save; shared Astro adapter delegates presentation to the existing Button component. |
| Heading, paragraph | Core RichText/save; frontend adapters handle heading levels/anchors and paragraph content. PHP heading-anchor bridge only enriches sourced data. |
| Image | Core media/caption/link editing/save; shared frontend adapter owns image/link/caption output and image-size data handling. |
| Quote, list/list-item | Core nested editing/save; shared adapters own frontend wrappers and content. |
| HTML | Core HTML editor/save; shared frontend adapter retains its existing restricted Zeffy rendering policy. |
| YouTube, Matterport | Core embed editor/save; shared provider registry selects existing frontend embed components. No new custom element or editor embed runtime. |

This audit required no production renderer/save changes and no content
migration. Do not copy native Gutenberg markup into Astro editor shells merely
to make all blocks look architecturally identical.

The shared browser regression includes 16 native core save/parse/reserialize
cases, including nested buttons/list items, group layouts, all heading levels,
linked/captioned image, quote, HTML and both embed providers. It does not load
external video/tour providers. Run
`node --experimental-vm-modules --test tests/group-layout-test.mjs` and
`php tests/heading-anchor.php` for the plugin's core data/layout bridges.

### Responsive listing controls

- Event listings (including upcoming events) and content listings share layout controls. Grid variants offer 3 or 4 columns; sliders expose items visible at Base, XS (425px), SM (720px), MD (1024px) and LG (1250px).
- Grid choices update the editor preview immediately through wrapper CSS variables, before server-rendered markup refreshes. Article-only lists use the same content-listing block and preview behavior.
- Slider settings retain the original `slidesPerPage`, `slidesPerPageXs`, `slidesPerPageSm`, `slidesPerPageMd` and `slidesPerPageLg` attribute names. Fractions such as `2.333` are preserved, not rounded to one decimal. Total query count remains independent.
- Defaults come from each block's schema: event listings use 1 / 2 / 3 / 4 / 3.5; content listings use 1 / 2 / 3 / 3 / 4. The inspector, preview sizing and PHP output use those defaults. Existing `grid-3`/`grid-4` variant values remain supported.
- Validate the editor controls with `node --experimental-vm-modules --test tests/listing-layout.test.mjs` and saved-attribute rendering with `wp eval-file /path/to/abcnorio-func/tests/listing-layout.php`.

## Content Listing Contract

- Grid/slider structure, navigation buttons, title/count and CTA slots come from populated component fixtures. PHP fills or removes slots using the existing block attributes; no parallel slider markup or CSS override file is needed.
- Both event and mixed-content listings use the same event-teaser renderer. Article cards replace fixture excerpts with sanitized post excerpts (preserving UTF-8), use `article_date` before publication date without timezone day shifts, and select an image-less fixture when needed.
- The REST endpoint remains responsible for allowed post types, tags and ordering. The block forwards manual IDs only in manual mode and preserves the returned item order.
- Run `wp eval-file /path/to/abcnorio-func/tests/content-listing-render.php` to check populated/empty grid and slider output, controls, card data and endpoint ordering.
- Event/content sliders share frontend navigation and lifecycle code, including responsive pagination-dot refresh and cleanup when server-rendered previews are replaced.

- Endpoint: `GET /wp-json/abcnorio/v1/content-listing`.
- Allowed `post_types[]` are strict and backend-limited to `event` and `article`.
- `count` is backend-capped at `50`.
- `tags[]` maps to `event_tag` for events and `post_tag` for articles (when taxonomy exists).
- Event Listing (Grid and Paged Grid) and Content Listing (Grid) offer 3 Col Grid and 4 Col Grid. Unspecified layouts default to four columns. Frontend uses the selected count at 1250px and above, three columns from 720px, and two below that. Editor previews immediately show the selected count regardless of canvas width. Slider and gallery layouts are unaffected.
- Plugin releases rebuild staging's editor bundle, not the mounted dev bundle. After editing block controls, run `./node_modules/.bin/wp-scripts build resources/js/index.js --output-path=build` in the plugin directory to refresh dev.
