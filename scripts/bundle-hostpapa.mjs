/**
 * Builds the two archives that get uploaded to HostPapa.
 *
 * This exists because HostPapa shared hosting has no SSH — the policy is
 * explicit, and it means no `composer install`, no `git pull`, and no shell of
 * any kind on the server. Everything the API needs, `vendor/` included, has to
 * arrive as files. Doing that by hand means picking the right folders out of a
 * Laravel tree every single deploy, and the two mistakes that costs are both
 * expensive: shipping the local `.env` overwrites the server's live PayPal and
 * database credentials, and forgetting `vendor/` produces a white screen with
 * nothing useful in the log.
 *
 * Output, in dist-hostpapa/:
 *
 *   api.zip          -> extract into the API's folder (see docs/13)
 *   public_html.zip  -> extract into the front end's document root
 *
 * Usage:
 *   cd api && composer install --no-dev --optimize-autoloader && cd ..
 *   cd web && npm run deploy:live && cd ..
 *   node scripts/bundle-hostpapa.mjs
 *
 * Afterwards run `composer install` in api/ again to get the dev packages back,
 * or the test suite will not run locally.
 */
import { execFileSync } from 'node:child_process';
import {
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'dist-hostpapa');
const api = join(root, 'api');
const web = join(root, 'deploy', 'web');

const fail = (message) => {
  console.error(`\n  ${message}\n`);
  process.exit(1);
};

if (!existsSync(join(api, 'vendor', 'autoload.php'))) {
  fail(
    'api/vendor is missing.\n  Run: cd api && composer install --no-dev --optimize-autoloader',
  );
}

if (!existsSync(join(web, 'index.html'))) {
  fail('deploy/web is empty.\n  Run: cd web && npm run deploy:live');
}

/*
 * Which build is in deploy/web. The same guard deploy-build.mjs uses, repeated
 * here because that one runs at build time and this runs at upload time — and
 * the archive is what actually reaches the public internet. A staging build
 * carries `Disallow: /`, which silently delists the entire site if it goes out
 * as production.
 */
const robots = join(web, 'robots.txt');
const isStaging =
  existsSync(robots) &&
  readFileSync(robots, 'utf8')
    .split('\n')
    .some((line) => line.trim() === 'Disallow: /');

rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

/*
 * Everything under api/ except: the local .env (live credentials that must not
 * travel, and which would overwrite the server's own), the test suite and its
 * caches, and any writable-runtime leftovers. `storage/` itself still has to
 * ship so Laravel finds the directory structure it expects.
 */
const skip = new Set([
  '.git',
  '.github',
  '.idea',
  '.phpunit.cache',
  '.phpunit.result.cache',
  'node_modules',
  'tests',
  'phpunit.xml',
]);

/*
 * Compiled views, framework caches and log files.
 *
 * `storage/app` is deliberately NOT here: load photos and carrier verification
 * documents live there and have to travel with the app.
 *
 * The alternation on `^` matters. This pattern began as `[\\/]storage[\\/]…`,
 * which needs a character before `storage` — and the paths handed to the filter
 * are relative, so they *start* with it. Nothing matched, and a local
 * `storage/logs/laravel.log` full of test-suite output was uploaded and then
 * served over HTTP.
 */
const runtimeJunk = (path) =>
  /(^|[\\/])storage[\\/](logs|framework[\\/](cache|sessions|views|testing))([\\/]|$)/.test(path);

/*
 * public/storage is a symlink made by `php artisan storage:link`, pointing at
 * an absolute path on whichever machine created it. Copying it would fail
 * outright on Windows, where symlinks need privilege, and if it did copy it
 * would point the server at a directory on a developer's laptop. The server
 * makes its own — docs/13 runs storage:link there as a one-shot cron.
 */
const isStorageSymlink = (path) => /^public[\\/]storage([\\/]|$)/.test(path);

/*
 * EVERY .env file, not a list of the ones we thought of.
 *
 * The first version of this named `.env`, `.env.backup` and `.env.production`
 * explicitly — and shipped `.env.backup-082415`, `.env.backup-084637`,
 * `.env.backup-201122` and `.env.backup-before-mailcleanup`, which were sitting
 * in the working directory. On the server those were served with HTTP 200: the
 * host's dotfile rule blocks the exact name `.env` and nothing else, so a
 * timestamped copy of it is an ordinary readable file containing a database
 * password.
 *
 * An allow-list of names cannot be right here, because the failure is silent
 * and the cost is a credential leak. Anything beginning `.env` stays home,
 * `.example` templates included — the server's .env is written by hand, so
 * none of them has a reason to travel.
 */
const isEnvFile = (path) => {
  const name = path.split(/[\\/]/).pop() ?? '';

  return name === '.env' || name.startsWith('.env.');
};

cpSync(api, join(out, 'api'), {
  recursive: true,
  filter: (source) => {
    const relative = source.slice(api.length + 1);

    if (relative === '') {
      return true;
    }

    const [top] = relative.split(/[\\/]/);

    if (skip.has(top) || skip.has(relative)) {
      return false;
    }

    return !runtimeJunk(relative) && !isStorageSymlink(relative) && !isEnvFile(relative);
  },
});

// Laravel needs these to exist and be writable; the filter above strips their
// contents but the directories themselves must survive the trip.
for (const dir of [
  'storage/app/public',
  'storage/framework/cache/data',
  'storage/framework/sessions',
  'storage/framework/views',
  'storage/logs',
  'bootstrap/cache',
]) {
  const target = join(out, 'api', dir);
  mkdirSync(target, { recursive: true });
  writeFileSync(join(target, '.gitkeep'), '');
}

cpSync(web, join(out, 'public_html'), { recursive: true });

/*
 * Compress-Archive rather than a zip dependency: this repository has no
 * root-level package.json and adding one for a single archive step is not
 * worth the maintenance.
 */
const zip = (folder) => {
  const source = join(out, folder);
  const archive = join(out, `${folder}.zip`);

  execFileSync(
    'powershell.exe',
    [
      '-NoProfile',
      '-Command',
      `Compress-Archive -Path '${source}\\*' -DestinationPath '${archive}' -Force`,
    ],
    { stdio: 'inherit' },
  );

  return archive;
};

zip('api');
zip('public_html');

console.log(`
  dist-hostpapa/api.zip          -> the API folder
  dist-hostpapa/public_html.zip  -> the front-end document root

  The local .env is deliberately NOT in api.zip. The server keeps its own.
`);

if (isStaging) {
  console.log('  robots.txt says Disallow: / — this is a STAGING build.\n');
} else {
  console.log('  robots.txt allows indexing — this is a PRODUCTION build.\n');
}
