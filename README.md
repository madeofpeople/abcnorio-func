# Custom functionality plugin

This plugin is Composer-first, PSR-4 autoloaded, and structured around declarative content model definitions plus small registrar classes. It is used with Bedrock and provides the site's custom post types, taxonomies, headless admin behavior, and other sundries.

## Component Ingestion Contract

- Source of truth for web component fixtures/assets is `node_modules/abcnorio-webcomponents/dist`.
- Plugin-owned runtime artifacts are copied to `resources/vendor/components/dist` during `npm run build`.
- Runtime PHP reads fixtures/assets only from plugin-local path (`resources/vendor/components/dist`).
- Runtime CSS is enqueued as a static plugin URL from that same plugin-local path.
- Contract is fail-loud: missing dist/manifest/css is a hard error.

### Component dep enqueue

- `manifest.components[name].deps` is a flat array of relative dist paths for transitive CSS/JS deps declared in fixture metadata.
- `ComponentIngestor::enqueue_component_deps(string $component_name)` infers asset type from file extension and enqueues under namespaced handles: `abcnorio-dep-{type}-{component}-{slug}-{hash}`.
- Handle is deterministic per path; WordPress deduplicates re-enqueues automatically.
- `ComponentIngestor::render()` calls `enqueue_component_deps` automatically.
- Direct-query blocks (`EventListingQuery`, `ContentListingQuery`) call `enqueue_component_deps` explicitly for each component they render, including child teasers.

1. Install dependencies in `abcnorio-func`.
2. Run `npm run build` in `abcnorio-func`.

## Admin CSS Contract

- Runtime admin stylesheet remains `resources/css/admin-styles.css`.
- Source token bundle is `resources/css/admin-tokens.scss` and compiles from `../abcnorio-astro/design-tokens/tokens`.
- WP-specific rules live in `resources/css/admin-overrides.css`.
- `npm run build:admin-css` regenerates `resources/css/admin-styles.css` by compiling tokens and appending overrides.

## Content Listing Contract

- Endpoint: `GET /wp-json/abcnorio/v1/content-listing`.
- Allowed `post_types[]` are strict and backend-limited to `event` and `article`.
- `count` is backend-capped at `50`.
- `time_filter` supports `all`, `upcoming`, `past` (event filtering uses `event_effective_end`).
- `tags[]` maps to `event_tag` for events and `post_tag` for articles (when taxonomy exists).
