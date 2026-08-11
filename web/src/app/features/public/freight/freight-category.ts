import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
} from '@angular/core';
import { RouterLink } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { Seo } from '../../../core/seo/seo.service';
import { Icon } from '../../../shared/icon';
import { Reveal } from '../../../shared/reveal.directive';
import { Ripple } from '../../../shared/ripple.directive';
import { SectionHead } from '../../../shared/section-head';
import { FREIGHT_CATEGORIES, FreightCategory, findCategory } from './freight-category.data';

/**
 * A landing page for one freight category, e.g. /boat-transport.
 *
 * One component, twelve routes: the layout is shared but every word on the page
 * comes from that category's own entry in `freight-category.data.ts`. Twelve
 * near-identical pages would be treated as doorway content by search engines
 * and would read as filler to a shipper looking for a specific answer.
 */
@Component({
  selector: 'fm-freight-category',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, Ripple, SectionHead],
  templateUrl: './freight-category.html',
  styleUrl: './freight-category.scss',
})
export class FreightCategoryPage {
  /** Bound from the route's `data.slug`, so each URL is its own entry point. */
  readonly slug = input.required<string>();

  private readonly seo = inject(Seo);

  protected readonly category = computed<FreightCategory>(
    // The routes are generated from the same list, so a miss is impossible
    // without a code change that would fail the build first.
    () => findCategory(this.slug()) ?? FREIGHT_CATEGORIES[0],
  );

  protected readonly related = computed(() =>
    this.category()
      .related.map((slug) => findCategory(slug))
      .filter((entry): entry is FreightCategory => entry !== undefined),
  );

  /** Every other category, for the footer index that ties the set together. */
  protected readonly all = FREIGHT_CATEGORIES;

  /**
   * The h1 split on `*accent*` markers, the same convention SectionHead uses.
   *
   * Split rather than innerHTML: no sanitiser involved, and the copy stays
   * readable in the data file.
   */
  protected readonly headingSegments = computed(() =>
    this.category()
      .heading.split(/\*([^*]+)\*/g)
      .map((text, index) => ({ text, accent: index % 2 === 1 }))
      .filter((segment) => segment.text !== ''),
  );

  constructor() {
    // An effect, not a one-shot. The related-category links move between two
    // routes served by this same component, so Angular reuses the instance and
    // only the input changes — a constructor-time call would leave the previous
    // page's title and JSON-LD in place.
    effect(() => this.applySeo(this.category()));
  }

  private applySeo(category: FreightCategory): void {
    const url = `${environment.siteUrl}/${category.slug}`;

    this.seo.apply({
      title: category.metaTitle,
      description: category.metaDescription,
      path: `/${category.slug}`,
      image: category.card.image,
      // One @graph rather than three separate scripts: the SEO service manages
      // a single JSON-LD block, and search engines read the graph the same way.
      structuredData: {
        '@context': 'https://schema.org',
        '@graph': [
          {
            '@type': 'Service',
            '@id': `${url}#service`,
            name: category.card.label,
            description: category.metaDescription,
            serviceType: category.card.label,
            areaServed: { '@type': 'Country', name: 'Australia' },
            provider: {
              '@type': 'Organization',
              name: 'FreightMove',
              url: environment.siteUrl,
            },
            hasOfferCatalog: {
              '@type': 'OfferCatalog',
              name: `${category.card.label} services`,
              itemListElement: category.moves.map((item) => ({
                '@type': 'Offer',
                itemOffered: { '@type': 'Service', name: item },
              })),
            },
          },
          {
            '@type': 'FAQPage',
            '@id': `${url}#faq`,
            mainEntity: category.faqs.map((faq) => ({
              '@type': 'Question',
              name: faq.q,
              acceptedAnswer: { '@type': 'Answer', text: faq.a },
            })),
          },
          {
            '@type': 'BreadcrumbList',
            '@id': `${url}#breadcrumbs`,
            itemListElement: [
              {
                '@type': 'ListItem',
                position: 1,
                name: 'Home',
                item: environment.siteUrl,
              },
              {
                '@type': 'ListItem',
                position: 2,
                name: 'Freight we handle',
                item: `${environment.siteUrl}/#freight-we-handle`,
              },
              { '@type': 'ListItem', position: 3, name: category.card.label, item: url },
            ],
          },
        ],
      },
    });
  }
}
