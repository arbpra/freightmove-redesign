/**
 * Public navigation model.
 *
 * Destinations are home-page fragments while the rest of the marketing pages
 * (Pricing, About, Contact, Blog) are still Phase 3 — see
 * docs/08-development-roadmap.md. When those routes land, swap `fragment` for
 * `path` on the affected items; the header renders either without changes.
 */
export interface NavLink {
  label: string;
  /** Router path — takes precedence over `fragment` when both are present. */
  path?: string;
  /** Home-page anchor id, used when the item has no page of its own yet. */
  fragment?: string;
  description?: string;
}

export interface NavGroup {
  label: string;
  /** Home-page section this group represents, for the active nav indicator. */
  section: string;
  links: NavLink[];
}

export const PUBLIC_NAV: NavGroup[] = [
  {
    label: 'Shippers',
    section: 'how-it-works',
    links: [
      {
        label: 'Post a Load',
        path: '/register',
        description: 'Tell us what, where and when — it takes two minutes.',
      },
      {
        label: 'How It Works',
        fragment: 'how-it-works',
        description: 'The five steps from posting to delivery.',
      },
      {
        label: 'Why Shippers Choose Us',
        fragment: 'why-freightmove',
        description: 'Verified carriers, competitive quotes, no obligation.',
      },
      {
        label: 'Get Quotes',
        fragment: 'get-quotes',
        description: 'Compare pricing from carriers on your route.',
      },
    ],
  },
  {
    label: 'Carriers',
    section: 'why-freightmove',
    links: [
      {
        // Goes to the board itself now, not to sign-up: looking is open, and
        // sending a carrier to a registration form to answer "is there freight
        // on my lane?" is the wrong order.
        label: 'Find Loads',
        path: '/load-board',
        description: 'Every load open for quotes, Australia wide.',
      },
      {
        label: 'Carrier Benefits',
        fragment: 'why-freightmove',
        description: 'Choose the loads that suit your fleet and lanes.',
      },
      {
        label: 'Join as a Carrier',
        path: '/register',
        description: 'Get verified and start quoting.',
      },
      {
        label: 'Subscription Plans',
        path: '/carriers-subscription',
        description: 'Monthly, quarterly and annual — plus a free trial.',
      },
      {
        label: 'Active Lanes',
        fragment: 'popular-routes',
        description: 'See where the freight is moving right now.',
      },
    ],
  },
  {
    label: 'Services',
    section: 'freight-we-handle',
    // Each of these is now a real page rather than a jump to a home-page
    // section, so the dropdown is genuine navigation and every entry is its own
    // search landing page.
    links: [
      { label: 'Heavy Haulage', path: '/heavy-haulage' },
      { label: 'General Freight', path: '/general-freight' },
      { label: 'Container Transport', path: '/container-transport' },
      { label: 'Machinery Transport', path: '/machinery-transport' },
      { label: 'Livestock Transport', path: '/livestock-transport' },
      { label: 'Boat Transport', path: '/boat-transport' },
      { label: 'All Freight Types', fragment: 'freight-we-handle' },
    ],
  },
  {
    label: 'Routes',
    section: 'popular-routes',
    links: [
      { label: 'Sydney → Melbourne', fragment: 'popular-routes' },
      { label: 'Brisbane → Perth', fragment: 'popular-routes' },
      { label: 'Melbourne → Brisbane', fragment: 'popular-routes' },
      { label: 'Adelaide → Darwin', fragment: 'popular-routes' },
      { label: 'All Popular Routes', fragment: 'popular-routes' },
    ],
  },
  {
    label: 'Resources',
    section: 'faq',
    // Guides, news and regulations belong to the blog, which is not in scope
    // for launch. When the Resources band returns to the home page (see
    // home.ts), point these back at the `resources` fragment.
    links: [
      { label: 'FAQs', fragment: 'faq' },
      { label: 'Freight Types', fragment: 'freight-we-handle' },
      { label: 'Popular Routes', fragment: 'popular-routes' },
      { label: 'Get Quotes', fragment: 'get-quotes' },
      { label: 'Contact Us', path: '/contact-us' },
    ],
  },
  {
    label: 'About Us',
    section: 'industries',
    links: [
      { label: 'Industries We Serve', fragment: 'industries' },
      { label: 'Customer Stories', fragment: 'testimonials' },
      { label: 'Why FreightMove', fragment: 'why-freightmove' },
      { label: 'Contact Us', path: '/contact-us' },
    ],
  },
];

export const CONTACT_PHONE = '1300 123 456';
export const CONTACT_PHONE_HREF = 'tel:1300123456';
