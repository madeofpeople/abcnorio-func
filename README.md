# Custom functionality plugin

This plugin is Composer-first, PSR-4 autoloaded, and structured around declarative content model definitions plus small registrar classes. It is used with Bedrock and provides the site's custom post types, taxonomies, headless admin behavior, and other sundries.

## Component Ingestion Contract

- Source of truth for web component fixtures/assets is `node_modules/abcnorio-webcomponents/dist`.
- Plugin-owned runtime artifacts are copied to `resources/vendor/components/dist` during `npm run build`.
- Runtime PHP reads fixtures/assets only from plugin-local path (`resources/vendor/components/dist`).
- Runtime CSS is enqueued as a static plugin URL from that same plugin-local path.
- Contract is fail-loud: missing dist/manifest/css is a hard error.

## Release Artifact Contract

- `build/` (webpack-compiled editor script, `wp-scripts build` output) is gitignored and never committed — this repo's git history stays source-only.
- Staging installs this plugin via Composer's `artifact` repository type (a folder of versioned tarballs), not a plain VCS checkout, so `build/` still reaches runtime without ever being tracked in git.
- Release tarballs are produced by `abcnorio-meta/scripts/build-plugin-release.sh` (invoke via `just build-plugin-release [tag]` from `abcnorio-meta/`): it exports the exact git tag via `git archive` into a clean scratch directory, runs `npm ci && npm run build` there, and packages the runtime footprint (`composer.json`, `custom-func.php`, `src/`, `resources/`, `build/`) into `abcnorio-meta/wp/plugin-artifacts/abcnorio-func-<version>.tar.gz`.
- `just update-wp-plugin staging <bump>` calls this same script automatically right after tagging a release — no separate manual step needed in normal use.
- Rebuilding an old release means: checkout that tag, then rerun the same build — the git tag plus a fresh build is the reproducible source of truth, not the tarball itself.
- Known caveat: `package.json` pins `abcnorio-webcomponents` to `github:...#main` (a floating ref). Rebuilding an old tag currently pulls whatever is on `main` at rebuild time, not the exact state that tag originally shipped with. Pinning to an immutable ref is a tracked follow-up, not yet done.

### Component dep enqueue

- `manifest.components[name].deps` is a flat array of relative dist paths for transitive CSS/JS deps declared in fixture metadata.
- `ComponentIngestor::enqueue_component_deps(string $component_name)` infers asset type from file extension and enqueues under namespaced handles: `abcnorio-dep-{type}-{component}-{slug}-{hash}`.
- Handle is deterministic per path; WordPress deduplicates re-enqueues automatically.
- `ComponentIngestor::render()` calls `enqueue_component_deps` automatically.
- Direct-query blocks (`EventListingQuery`, `ContentListingQuery`) call `enqueue_component_deps` explicitly for each component they render, including child teasers.

1. Install dependencies in `abcnorio-func`.
2. Run `npm run build` in `abcnorio-func`.

## Admin CSS Contract

- Admin CSS is direct: `ComponentIngestor::enqueueRuntimeStyles()` plus `resources/css/admin-overrides.css`.
- `resources/css/admin-overrides.css` stays manual and small.
- No generated `resources/css/admin-styles.css` is part of the runtime contract.

## Content Listing Contract

- Endpoint: `GET /wp-json/abcnorio/v1/content-listing`.
- Allowed `post_types[]` are strict and backend-limited to `event` and `article`.
- `count` is backend-capped at `50`.
- `tags[]` maps to `event_tag` for events and `post_tag` for articles (when taxonomy exists).
