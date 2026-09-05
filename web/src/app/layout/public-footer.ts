import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../shared/icon';
import { Wordmark } from '../shared/wordmark';
import {
  CONTACT_EMAIL,
  CONTACT_EMAIL_HREF,
  CONTACT_PHONE,
  CONTACT_PHONE_HREF,
  NavLink,
  SOCIAL_LINKS,
} from './public-nav';

interface FooterColumn {
  heading: string;
  links: NavLink[];
}

@Component({
  selector: 'fm-public-footer',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Wordmark],
  templateUrl: './public-footer.html',
  styleUrl: './public-footer.scss',
})
export class PublicFooter {
  protected readonly phone = CONTACT_PHONE;
  protected readonly phoneHref = CONTACT_PHONE_HREF;
  protected readonly email = CONTACT_EMAIL;
  protected readonly emailHref = CONTACT_EMAIL_HREF;
  protected readonly year = new Date().getFullYear();

  // Shared with the header's top bar, so the two cannot drift apart.
  protected readonly socials = SOCIAL_LINKS;

  protected readonly columns: FooterColumn[] = [
    {
      heading: 'For Shippers',
      links: [
        { label: 'Post a Load', path: '/register' },
        { label: 'How It Works', fragment: 'how-it-works' },
        { label: 'Services', fragment: 'freight-we-handle' },
        { label: 'Popular Routes', fragment: 'popular-routes' },
        { label: 'Pricing Guide', fragment: 'get-quotes' },
      ],
    },
    {
      heading: 'For Carriers',
      links: [
        { label: 'Find Loads', path: '/register' },
        { label: 'How It Works', fragment: 'how-it-works' },
        { label: 'Carrier Benefits', fragment: 'why-freightmove' },
        { label: 'Active Lanes', fragment: 'popular-routes' },
        { label: 'Join as Carrier', path: '/register' },
      ],
    },
    {
      heading: 'Company',
      links: [
        { label: 'Why FreightMove', fragment: 'why-freightmove' },
        { label: 'Industries We Serve', fragment: 'industries' },
        { label: 'Customer Stories', fragment: 'testimonials' },
        { label: 'Contact Us', path: '/contact-us' },
      ],
    },
    {
      heading: 'Resources',
      links: [
        // 'Guides & Tips' and 'News' return with the blog; see home.ts.
        { label: 'FAQs', fragment: 'faq' },
        { label: 'Popular Routes', fragment: 'popular-routes' },
        { label: 'Freight Types', fragment: 'freight-we-handle' },
        { label: 'Get Quotes', fragment: 'get-quotes' },
      ],
    },
  ];
}
