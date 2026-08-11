import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../shared/icon';
import { IconName } from '../shared/icons';
import { Wordmark } from '../shared/wordmark';
import { CONTACT_PHONE, CONTACT_PHONE_HREF, NavLink } from './public-nav';

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
  protected readonly email = 'info@freightmove.au';
  protected readonly year = new Date().getFullYear();

  protected readonly socials: { label: string; icon: IconName; href: string }[] = [
    { label: 'FreightMove on Facebook', icon: 'facebook', href: 'https://facebook.com' },
    { label: 'FreightMove on LinkedIn', icon: 'linkedin', href: 'https://linkedin.com' },
    { label: 'FreightMove on Instagram', icon: 'instagram', href: 'https://instagram.com' },
    { label: 'FreightMove on YouTube', icon: 'youtube', href: 'https://youtube.com' },
  ];

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
