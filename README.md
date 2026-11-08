# Custom functionality plugin

This plugin is Composer-first, PSR-4 autoloaded, and structured around declarative content model definitions plus small registrar classes. It is used with Bedrock and provides the site's custom post types, taxonomies, headless admin behavior, and other sundries.

## Component Ingestion Contract

- Source of truth for web component fixtures/assets is the webcomponents build output. Normal `npm run build` ingests `node_modules/abcnorio-webcomponents/dist`; the meta repo's `just plugin-dev-assets` opts into the local sibling `abcnorio-webcomponents/dist` for WordPress preview work.
- Plugin-owned runtime artifacts are copied to `resources/vendor/components/dist` during `npm run build`.
- Runtime PHP reads fixtures/assets only from plugin-local path (`resources/vendor/components/dist`).
- Runtime CSS is enqueued as a static plugin URL from that same plugin-local path.
- Contract is fail-loud: missing dist/manifest/css is a hard error.

## Release Artifact Contract

- `build/` (webpack-compiled editor script, `wp-scripts build` output) is gitignored and never committed — this repo's git history stays source-only.
- Staging installs this plugin via Composer's `artifact` repository type (a folder of versioned tarballs), not a plain VCS checkout, so `build/` still reaches runtime without ever being tracked in git.
- Release tarballs are produced by `abcnorio-meta/scripts/build-plugin-release.sh` (invoke via `just plugin-artifact [tag]` from `abcnorio-meta/`): it exports the exact git tag via `git archive` into a clean scratch directory, runs `npm ci && npm run build` there, and packages the runtime footprint (`composer.json`, `custom-func.php`, `src/`, `resources/`, `build/`) into `abcnorio-meta/wp/plugin-artifacts/abcnorio-func-<version>.tar.gz`.
- From `abcnorio-meta`, `just plugin-dev-assets` builds local webcomponents and rebuilds the mounted dev plugin preview assets. It does not bump versions, commit, tag, push, or deploy.
- `just plugin-release staging <bump>` builds and validates one candidate artifact from clean `HEAD` before changing the source version, committing, tagging, or pushing. It verifies the webcomponents manifest but does not rebuild the live webcomponents `dist/` tree.
- Rebuild an old release with `just plugin-artifact vX.Y.Z`; the script exports that tag and `npm ci` uses the exact dependency commit recorded in its `package-lock.json`.
- Keep the tagged `package-lock.json` as the dependency source of truth; `npm install` can refresh the `#main` Git reference and should not be used for historical artifact rebuilds.

### Component dep enqueue

- `manifest.components[name].deps` is a flat array of relative dist paths explicitly declared in fixture metadata for WordPress, including direct assets when needed.
- `ComponentIngestor::enqueue_component_deps(string $component_name)` infers asset type from file extension and enqueues under namespaced handles: `abcnorio-dep-{type}-{component}-{slug}-{hash}`.
- Handle is deterministic per path; WordPress deduplicates re-enqueues automatically.
- WordPress block renderers call `enqueue_component_deps` explicitly for each component they need, including child teasers. Declared JS dependencies load as ES modules.
- Gallery does not enqueue its frontend script in WordPress; the editor provides its own controls.

1. Install dependencies in `abcnorio-func`.
2. Run `npm run build` in `abcnorio-func`.

## Admin CSS Contract

- Admin CSS is direct: `ComponentIngestor::enqueueRuntimeStyles()` plus `resources/css/admin-overrides.css`.
- `resources/css/admin-overrides.css` stays manual and small.
- No generated `resources/css/admin-styles.css` is part of the runtime contract.
- Component appearance comes from ingested component CSS; keep only admin
  controls and editor interaction exceptions in admin overrides where possible.
- To check block output and dependency enqueues in a WordPress environment, run
  `wp eval-file /path/to/abcnorio-func/tests/component-ingestion-test.php`.

## Group Layout

New Groups use normal flow; Row, Stack, and Grid keep their layout types.

## Public form messages

Settings > ABC No Rio > Public form messages controls these site-wide
messages: success, delivery failure, sending, too-fast submission, validation
summary, connection failure, missing form, unexpected error, required field,
invalid selection, invalid email, and minimum length. Blank settings use the
defaults. Field validation messages use `{field}` for the field label;
minimum-length messages also use `{min_length}`. Delivery failures are logged
without form values; check the configured mail transport's logs for details.

Run `wp eval-file /path/to/abcnorio-func/tests/public-form-messages-test.php`
in a WordPress environment with an email-configured form containing a required
field. The test intercepts mail and does not send submissions.

## Content Listing Contract

- Endpoint: `GET /wp-json/abcnorio/v1/content-listing`.
- Allowed `post_types[]` are strict and backend-limited to `event` and `article`.
- `count` is backend-capped at `50`.
- `tags[]` maps to `event_tag` for events and `post_tag` for articles (when taxonomy exists).
