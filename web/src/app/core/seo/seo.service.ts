import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';
import { Meta, Title } from '@angular/platform-browser';

import { environment } from '../../../environments/environment';

export interface SeoPage {
  /** Full <title>. Keep under ~60 characters so search results do not truncate. */
  title: string;
  /** Meta description. Aim for 140–160 characters. */
  description: string;
  /** Route path, e.g. '/contact-us'. Becomes the canonical and og:url. */
  path: string;
  /** Optional absolute or root-relative share image. */
  image?: string;
  /** Optional schema.org payload rendered as JSON-LD. */
  structuredData?: Record<string, unknown>;
  /**
   * Keep the page out of search results. For pages that are private, transient,
   * or carry a token in the URL — a password reset link must never be indexed.
   */
  noIndex?: boolean;
}

/**
 * Applies per-page SEO: title, description, canonical, Open Graph, Twitter card
 * and JSON-LD.
 *
 * The canonical link and JSON-LD script are reused across navigations rather
 * than appended each time, so a session cannot accumulate duplicates — search
 * engines treat more than one canonical on a page as no canonical at all.
 */
@Injectable({ providedIn: 'root' })
export class Seo {
  private readonly title = inject(Title);
  private readonly meta = inject(Meta);
  private readonly document = inject(DOCUMENT);

  private static readonly JSON_LD_ID = 'fm-structured-data';

  apply(page: SeoPage): void {
    const url = `${environment.siteUrl}${page.path}`;
    const image = page.image
      ? page.image.startsWith('http')
        ? page.image
        : `${environment.siteUrl}${page.image}`
      : undefined;

    this.title.setTitle(page.title);

    this.meta.updateTag({ name: 'description', content: page.description });
    this.meta.updateTag({ property: 'og:title', content: page.title });
    this.meta.updateTag({ property: 'og:description', content: page.description });
    this.meta.updateTag({ property: 'og:url', content: url });
    this.meta.updateTag({ property: 'og:type', content: 'website' });
    this.meta.updateTag({ property: 'og:site_name', content: 'FreightMove' });
    this.meta.updateTag({ property: 'og:locale', content: 'en_AU' });
    this.meta.updateTag({ name: 'twitter:card', content: 'summary_large_image' });
    this.meta.updateTag({ name: 'twitter:title', content: page.title });
    this.meta.updateTag({ name: 'twitter:description', content: page.description });

    if (image) {
      this.meta.updateTag({ property: 'og:image', content: image });
      this.meta.updateTag({ name: 'twitter:image', content: image });
    } else {
      this.meta.removeTag("property='og:image'");
      this.meta.removeTag("name='twitter:image'");
    }

    // Removed rather than left in place when absent: this app never reloads
    // between routes, so a stale noindex would follow the user from the reset
    // page onto every marketing page they visit next.
    if (page.noIndex) {
      this.meta.updateTag({ name: 'robots', content: 'noindex, nofollow' });
    } else {
      this.meta.removeTag("name='robots'");
    }

    this.setCanonical(url);
    this.setStructuredData(page.structuredData);
  }

  private setCanonical(url: string): void {
    let link = this.document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');

    if (!link) {
      link = this.document.createElement('link');
      link.setAttribute('rel', 'canonical');
      this.document.head.appendChild(link);
    }

    link.setAttribute('href', url);
  }

  private setStructuredData(data?: Record<string, unknown>): void {
    const existing = this.document.getElementById(Seo.JSON_LD_ID);

    if (!data) {
      existing?.remove();
      return;
    }

    const script = existing ?? this.document.createElement('script');
    script.id = Seo.JSON_LD_ID;
    script.setAttribute('type', 'application/ld+json');
    script.textContent = JSON.stringify(data);

    if (!existing) {
      this.document.head.appendChild(script);
    }
  }
}
