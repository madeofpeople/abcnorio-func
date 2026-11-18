import { cpSync, mkdirSync, readFileSync, readdirSync, renameSync, writeFileSync, rmSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const destination = fileURLToPath(new URL('../resources/vendor/components/', import.meta.url));
if (process.argv[2] === '--clean') {
  rmSync(path.join(destination, 'dev-current.json'), { force: true });
  mkdirSync(destination, { recursive: true });
  for (const entry of readdirSync(destination, { withFileTypes: true })) {
    if (/^dev-[a-f0-9]{24}(?:\.json)?$/.test(entry.name)) {
      rmSync(path.join(destination, entry.name), { recursive: true, force: true });
    }
  }
  console.log('[components] Development generations removed. Normal dist is active.');
  process.exit(0);
}
const source = process.env.ABCNORIO_COMPONENTS_DIST_SOURCE;
if (!source || !path.isAbsolute(source)) {
  throw new Error('Development publication requires an absolute ABCNORIO_COMPONENTS_DIST_SOURCE.');
}
const generation = `dev-${randomBytes(12).toString('hex')}`;
const staging = path.join(destination, generation);
const pointer = path.join(destination, `${generation}.json`);
mkdirSync(destination, { recursive: true });
try {
  cpSync(source, staging, { recursive: true, errorOnExist: true, force: false });
  const validation = spawnSync(process.execPath, ['src/util/validate-manifest.js'], {
    cwd: path.dirname(source),
    env: { ...process.env, ABCNORIO_COMPONENTS_DIST_DIR: staging },
    stdio: 'inherit',
  });
  if (validation.error) throw validation.error;
  if (validation.status !== 0) throw new Error('Development publication validation failed.');
  function rewrite(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const file = path.join(dir, entry.name);
      if (entry.isDirectory()) rewrite(file);
      else if (entry.name.endsWith('.css')) {
        writeFileSync(file, readFileSync(file, 'utf8').replaceAll('url(/assets/', 'url(../assets/'));
      }
    }
  }
  rewrite(path.join(staging, 'styles'));
  writeFileSync(pointer, JSON.stringify(generation) + '\n', { flag: 'wx' });
  renameSync(pointer, path.join(destination, 'dev-current.json'));
  console.log(`[components] Ready: ${generation}. Refresh WordPress to see changes.`);
} catch (error) {
  rmSync(pointer, { force: true });
  rmSync(staging, { recursive: true, force: true });
  throw error;
}
