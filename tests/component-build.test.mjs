import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

function setup(t) {
  const root = mkdtempSync(path.join(os.tmpdir(), 'component-build-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  mkdirSync(path.join(root, 'scripts'));
  mkdirSync(path.join(root, 'bin'));
  copyFileSync(new URL('../scripts/build-components.sh', import.meta.url), path.join(root, 'scripts/build-components.sh'));
  writeFileSync(path.join(root, 'scripts/ingest-components-dist.sh'), 'echo ingest >> "$BUILD_TEST_LOG"\n');
  return root;
}

function run(root, source, exit = '0') {
  return spawnSync('bash', [path.join(root, 'scripts/build-components.sh')], {
    encoding: 'utf8',
    env: {
      ...process.env,
      PATH: `${path.join(root, 'bin')}:${process.env.PATH}`,
      ABCNORIO_COMPONENTS_DIST_SOURCE: source ? path.join(source, 'dist') : '',
      BUILD_TEST_LOG: path.join(root, 'calls'),
      BUILD_TEST_EXIT: exit,
    },
  });
}

function addSource(source) {
  mkdirSync(path.join(source, 'scripts'), { recursive: true });
  writeFileSync(path.join(source, 'astro.config.mjs'), '');
  writeFileSync(path.join(source, 'scripts/build.sh'), 'echo build >> "$BUILD_TEST_LOG"\nexit "${BUILD_TEST_EXIT:-0}"\n');
}

test('default build compiles installed source before ingestion without requiring dist', (t) => {
  const root = setup(t);
  const source = path.join(root, 'node_modules/abcnorio-webcomponents');
  addSource(source);
  const result = run(root);
  assert.equal(result.status, 0, result.stderr);
  assert.equal(readFileSync(path.join(root, 'calls'), 'utf8'), 'build\ningest\n');
});

test('local source override uses the same build and stops before ingestion on failure', (t) => {
  const root = setup(t);
  const source = path.join(root, 'sibling source');
  addSource(source);
  const result = run(root, source, '17');
  assert.equal(result.status, 17);
  assert.equal(readFileSync(path.join(root, 'calls'), 'utf8'), 'build\n');
});

test('old dist without build sources fails explicitly instead of ingesting stale output', (t) => {
  const root = setup(t);
  const source = path.join(root, 'old-package');
  mkdirSync(path.join(source, 'dist'), { recursive: true });
  writeFileSync(path.join(source, 'dist/manifest.json'), '{}');
  const result = run(root, source);
  assert.equal(result.status, 1);
  assert.match(result.stderr, /Missing webcomponents build sources/);
});
