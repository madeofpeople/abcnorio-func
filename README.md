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
  sources are an error, never a fallback to stale dist. Live SCSS does not replace
  production CSS, scripts, manifests, or HTML fixtures.
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

### Local live SCSS

After building/ingesting the updated webcomponents package once, development
WordPress editors always load migrated styles from the existing Astro frontend.
The existing `DEV_FRONTEND_URL` environment variable supplies the browser-accessible
origin; the local Compose override sets it from `DEV_ASTRO_PORT`. There is no
database setting, mode toggle, or packaged fallback in development.

Hero, group, gallery, event listing, event teaser, content listing, article teaser and their manifest-declared
editor styles load from Astro. Save SCSS and refresh
again to see changes, including imports and design-token changes. No component
build/ingestion is needed for these SCSS edits. This is refresh-based, not HMR.

Other components keep their packaged styles. Migrated component rules are separate
assets, not duplicated in the combined fixture CSS. Shared dependency handles
deduplicate listing styles. Frontend WordPress requests stay packaged.

WordPress `local`/`development` environments use live editor styles;
`staging`/`production` environments always use built CSS. Keep Astro running in
development: missing configuration is reported as an error, and failed stylesheet
requests do not silently fall back to packaged styles.
JS, templates, new manifest entries, and unmigrated components still use normal builds.

Validate the loader without WordPress using `php tests/component-styles.php`.

### Gallery editor

- Group and gallery component styles load together. Gallery layout and editor CSS belong to the webcomponents package; admin overrides retain only WordPress-specific inspector adjustments.
- In the editor, gallery groups share one full-width frame, with fit-content, left-aligned headings. Outermost dark groups remain transparent; their descendants receive the dark background and light text.
- React retains ownership of image InnerBlocks. Slider previews scroll the existing editable track (including keyboard-selected images) rather than running the frontend carousel's DOM/lightbox setup.
- Paged-grid previews use the component's entering/leaving animations, respect reduced motion, and retain the tallest visited grid height until a resize or content/settings change.
- Navigation changes preview state only, not saved block attributes. Run editor behavior checks with `node --test tests/gallery-preview.test.mjs`.

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
