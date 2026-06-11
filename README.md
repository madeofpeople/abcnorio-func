# Custom functionality plugin

This plugin is Composer-first, PSR-4 autoloaded, and structured around declarative content model definitions plus small registrar classes. It is used with Bedrock and provides the site's custom post types, taxonomies, headless admin behavior, and other sundries.

## Component Ingestion Contract

- Source of truth for web component fixtures/assets is `node_modules/abcnorio-webcomponents/dist`.
- Plugin-owned runtime artifacts are copied to `resources/vendor/components/dist` during `npm run build`.
- Runtime PHP reads fixtures/assets only from plugin-local path (`resources/vendor/components/dist`).
- Runtime CSS is enqueued as a static plugin URL from that same plugin-local path.
- Contract is fail-loud: missing dist/manifest/css is a hard error.

Build flow:

1. Install dependencies in `abcnorio-func`.
2. Run `npm run build` in `abcnorio-func`.

## Content Listing Contract

- Endpoint: `GET /wp-json/abcnorio/v1/content-listing`.
- Allowed `post_types[]` are strict and backend-limited to `event` and `article`.
- `count` is backend-capped at `50`.
- `time_filter` supports `all`, `upcoming`, `past` (event filtering uses `event_effective_end`).
- `tags[]` maps to `event_tag` for events and `post_tag` for articles (when taxonomy exists).
