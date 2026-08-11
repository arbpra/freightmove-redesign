import { ChangeDetectionStrategy, Component } from '@angular/core';

import { CtaBand } from './sections/cta-band';
import { Faq } from './sections/faq';
import { FreightTypes } from './sections/freight-types';
import { HeroSplit } from './sections/hero-split';
import { HowItWorks } from './sections/how-it-works';
import { Industries } from './sections/industries';
import { PopularRoutes } from './sections/popular-routes';
import { LiveBoard } from './sections/live-board';
import { StatsStrip } from './sections/stats-strip';
import { Testimonials } from './sections/testimonials';
import { WhyChoose } from './sections/why-choose';

/**
 * The marketing home page. Each band is its own component so the other Phase 3
 * pages (Services, Routes, How It Works) can reuse them, and so the section ids
 * the public nav links to live next to their content.
 *
 * The quote-search band was replaced by the live board: a guest cannot post a
 * load, so the form sent them to registration and discarded the lane they had
 * typed on the way. `quote-search` is kept in ./sections for the signed-in
 * "post a load" flow, where the same fields do work. The `get-quotes` id stays
 * on the replacement so the nav links that point at it still land somewhere
 * sensible.
 *
 * The Resources ("Learn & Grow") band is built and maintained in
 * ./sections/resources but is not rendered here — the blog is out of scope for
 * launch. To bring it back: import Resources, add it to `imports`, and drop
 * `<fm-resources id="resources" />` in above <fm-faq>. The nav and footer links
 * that pointed at it were repointed at the FAQ; see public-nav.ts.
 */
@Component({
  selector: 'fm-home',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HeroSplit,
    LiveBoard,
    StatsStrip,
    HowItWorks,
    WhyChoose,
    FreightTypes,
    Industries,
    PopularRoutes,
    Testimonials,
    Faq,
    CtaBand,
  ],
  template: `
    <fm-hero-split />
    <fm-live-board id="get-quotes" />
    <fm-stats-strip />
    <fm-how-it-works id="how-it-works" />
    <fm-why-choose id="why-freightmove" />
    <fm-freight-types id="freight-we-handle" />
    <fm-industries id="industries" />
    <fm-popular-routes id="popular-routes" />
    <fm-testimonials id="testimonials" />
    <fm-faq id="faq" />
    <fm-cta-band />
  `,
  styles: `
    :host {
      display: block;
    }

    /* Anchored sections must clear the sticky header when jumped to. */
    [id] {
      scroll-margin-top: calc(var(--fm-header-h) + 1rem);
    }
  `,
})
export class Home {}
