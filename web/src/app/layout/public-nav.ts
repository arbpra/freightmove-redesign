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
        // Sign in, not sign up. 543 accounts came across from the previous
        // site, so whoever clicks this most likely already has one — and the
        // login page offers registration, while the registration form does
        // not offer a way back.
        label: 'Post a Load',
        path: '/login',
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

export const CONTACT_PHONE = '+61 407 243 242';
export const CONTACT_PHONE_HREF = 'tel:+61407243242';

/**
 * The public email address.
 *
 * Here rather than inline because the header, the footer, the contact page and
 * its JSON-LD all state it, and four copies of an address is three chances for
 * them to disagree about how to reach the company.
 */
export const CONTACT_EMAIL = 'peter.freightmove@gmail.com';
export const CONTACT_EMAIL_HREF = 'mailto:peter.freightmove@gmail.com';

/**
 * Where the social icons point.
 *
 * Shared by the top bar and the footer so the two cannot drift apart. The
 * hrefs are placeholders — bare domains, not real profiles — and are listed in
 * docs/08 among the things to settle before this replaces the live site.
 */
export interface SocialLink {
  label: string;
  icon: 'facebook' | 'linkedin' | 'instagram' | 'youtube';
  href: string;
}

export const SOCIAL_LINKS: SocialLink[] = [
  { label: 'FreightMove on Facebook', icon: 'facebook', href: 'https://facebook.com' },
  { label: 'FreightMove on LinkedIn', icon: 'linkedin', href: 'https://linkedin.com' },
  { label: 'FreightMove on Instagram', icon: 'instagram', href: 'https://instagram.com' },
  { label: 'FreightMove on YouTube', icon: 'youtube', href: 'https://youtube.com' },
];
