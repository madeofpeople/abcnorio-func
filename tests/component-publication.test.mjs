import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

test('publication switches only complete validated generations and preserves old assets', t => {
  const root = mkdtempSync(path.join(os.tmpdir(), 'component-publication-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  const plugin = path.join(root, 'plugin');
  const source = path.join(root, 'source');
  mkdirSync(path.join(plugin, 'scripts'), { recursive: true });
  mkdirSync(path.join(source, 'dist/styles'), { recursive: true });
  mkdirSync(path.join(source, 'src/util'), { recursive: true });
  cpSync(new URL('../scripts/publish-components-dev.mjs', import.meta.url), path.join(plugin, 'scripts/publish-components-dev.mjs'));
  writeFileSync(path.join(source, 'src/util/validate-manifest.js'), 'process.exit(process.env.TEST_FAILURE ? 1 : 0);');
  const base = path.join(plugin, 'resources/vendor/components');
  const pointer = path.join(base, 'dev-current.json');
  const run = failure => spawnSync(process.execPath, [path.join(plugin, 'scripts/publish-components-dev.mjs')], {
    encoding: 'utf8',
    env: { ...process.env, ABCNORIO_COMPONENTS_DIST_SOURCE: path.join(source, 'dist'), TEST_FAILURE: failure ? '1' : '' },
  });
  writeFileSync(path.join(source, 'dist/manifest.json'), '{"revision":1}');
  writeFileSync(path.join(source, 'dist/styles/test.css'), 'a{background:url(/assets/font.woff2)}');
  assert.equal(run(false).status, 0);
  const first = JSON.parse(readFileSync(pointer));
  assert.match(first, /^dev-[a-f0-9]{24}$/);
  assert.match(readFileSync(path.join(base, first, 'styles/test.css'), 'utf8'), /url\(\.\.\/assets/);
  writeFileSync(path.join(source, 'dist/manifest.json'), '{"revision":2}');
  assert.equal(run(false).status, 0);
  const second = JSON.parse(readFileSync(pointer));
  assert.notEqual(first, second);
  assert.equal(JSON.parse(readFileSync(path.join(base, first, 'manifest.json'))).revision, 1);
  assert.equal(JSON.parse(readFileSync(path.join(base, second, 'manifest.json'))).revision, 2);
  assert.equal(run(true).status, 1);
  assert.equal(JSON.parse(readFileSync(pointer)), second);
  assert.equal(readdirSync(base).filter(name => /^dev-[a-f0-9]{24}$/.test(name)).length, 2);
  assert(!existsSync(path.join(base, 'dist')));
  mkdirSync(path.join(base, 'dist'));
  writeFileSync(path.join(base, 'dist/keep.txt'), 'release output');
  const clean = spawnSync(process.execPath, [path.join(plugin, 'scripts/publish-components-dev.mjs'), '--clean']);
  assert.equal(clean.status, 0);
  assert(!existsSync(pointer));
  assert.deepEqual(readdirSync(base), ['dist']);
  assert.equal(readFileSync(path.join(base, 'dist/keep.txt'), 'utf8'), 'release output');
});
