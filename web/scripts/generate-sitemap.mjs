/**
 * Writes public/sitemap.xml and public/robots.txt.
 *
 * Generated rather than hand-maintained: the freight category list is the
 * source of truth for the routes, the pages and now the sitemap, so adding a
 * category cannot leave it undiscoverable. A hand-written sitemap goes stale
 * the first time someone adds a page in a hurry.
 *
 * Run via `npm run sitemap`, which the build script calls first.
 */
import { writeFileSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..');

// Read from `--site=` first so the npm scripts work identically on Windows,
// macOS and CI — `VAR=x cmd` is not valid syntax in cmd.exe or PowerShell.
const siteArg = process.argv.find((arg) => arg.startsWith('--site='))?.slice('--site='.length);
const SITE = siteArg ?? process.env.SITE_URL ?? 'https://www.freightmove.au';

/**
 * Anything that is not the canonical production host is treated as staging and
 * told not to index itself.
 *
 * A full copy of the site on a public subdomain is duplicate content: it can
 * cannibalise or outrank the real pages, and it puts customers on a test build
 * if they find it. Opt-out by host rather than by a flag someone has to
 * remember to pass — forgetting the flag is the failure mode that costs you.
 */
const IS_PRODUCTION_HOST = SITE === 'https://www.freightmove.au';

/**
 * Pulled straight out of the TypeScript source with a regex rather than by
 * importing it: the data file imports Angular-flavoured modules that Node
 * cannot load without a build step, and the slugs are the only part needed.
 */
function freightSlugs() {
  const source = readFileSync(
    join(root, 'src/app/features/public/freight/freight-category.data.ts'),
    'utf8',
  );

  const slugs = [...source.matchAll(/^\s{4}slug: '([a-z-]+)',$/gm)].map((match) => match[1]);

  if (slugs.length === 0) {
    throw new Error('No freight slugs found — has freight-category.data.ts changed shape?');
  }

  return slugs;
}

/** Priority is a hint, not a ranking factor; kept simple and honest. */
const staticPages = [
  { path: '/', priority: '1.0', changefreq: 'daily' },
  { path: '/load-board', priority: '0.9', changefreq: 'hourly' },
  { path: '/carriers-subscription', priority: '0.9', changefreq: 'monthly' },
  { path: '/contact-us', priority: '0.6', changefreq: 'yearly' },
  { path: '/register', priority: '0.8', changefreq: 'monthly' },
  { path: '/login', priority: '0.3', changefreq: 'yearly' },
];

const today = new Date().toISOString().slice(0, 10);

const urls = [
  ...staticPages,
  ...freightSlugs().map((slug) => ({
    path: `/${slug}`,
    priority: '0.8',
    changefreq: 'monthly',
  })),
];

const sitemap = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls
  .map(
    (url) => `  <url>
    <loc>${SITE}${url.path}</loc>
    <lastmod>${today}</lastmod>
    <changefreq>${url.changefreq}</changefreq>
    <priority>${url.priority}</priority>
  </url>`,
  )
  .join('\n')}
</urlset>
`;

// Signed-in areas have nothing to index and should never appear in results.
const robots = IS_PRODUCTION_HOST
  ? `User-agent: *
Allow: /

Disallow: /shipper/
Disallow: /carrier/
Disallow: /admin/
Disallow: /messages
Disallow: /account/
Disallow: /reset-password/
Disallow: /forgot-password

Sitemap: ${SITE}/sitemap.xml
`
  : `# Staging build (${SITE}) — deliberately kept out of every index.
User-agent: *
Disallow: /
`;

writeFileSync(join(root, 'public/sitemap.xml'), sitemap);
writeFileSync(join(root, 'public/robots.txt'), robots);

console.log(
  `sitemap.xml written with ${urls.length} URLs for ${SITE}` +
    (IS_PRODUCTION_HOST ? '' : ' — robots.txt set to Disallow: / (staging)'),
);
