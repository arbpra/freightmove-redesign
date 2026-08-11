/**
 * Copies a finished Angular build into ../deploy/web, which is committed and
 * pulled onto SiteGround.
 *
 * Shared hosting has no Node, so the bundle cannot be built on the server. It is
 * built here and the built files travel through git — unusual for build output,
 * and deliberate. `deploy/web` is wiped first so files removed by a build (old
 * hashed chunks, a deleted page) do not linger on the server as orphans.
 *
 * Usage: node scripts/deploy-build.mjs   (run by `npm run deploy`)
 */
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const built = join(here, '..', 'dist', 'web', 'browser');
const target = join(here, '..', '..', 'deploy', 'web');

if (!existsSync(built)) {
  console.error(
    `No build found at ${built}\nRun "npm run build:staging" or "npm run build" first.`,
  );
  process.exit(1);
}

// Guard against shipping a build made for the wrong host: robots.txt is the
// cheapest tell, and shipping a production robots to staging would let the test
// site be indexed.
const robots = join(built, 'robots.txt');
const isStaging = existsSync(robots) && readFileSync(robots, 'utf8').includes('Disallow: /');

if (existsSync(target)) {
  rmSync(target, { recursive: true, force: true });
}

mkdirSync(target, { recursive: true });
cpSync(built, target, { recursive: true });

const files = readdirSync(target).length;
const bytes = readdirSync(target)
  .map((name) => statSync(join(target, name)))
  .filter((stat) => stat.isFile())
  .reduce((total, stat) => total + stat.size, 0);

console.log(
  `deploy/web updated — ${files} entries, ${(bytes / 1024).toFixed(0)} KB at the top level`,
);
console.log(
  isStaging
    ? 'robots.txt says Disallow: / — this is a STAGING build.'
    : 'robots.txt allows indexing — this is a PRODUCTION build.',
);
console.log('\nNext: git add deploy && git commit && git push, then pull on SiteGround.');
