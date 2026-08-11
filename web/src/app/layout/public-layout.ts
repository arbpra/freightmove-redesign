import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

import { PublicFooter } from './public-footer';
import { PublicHeader } from './public-header';

/** Chrome for the marketing pages: sticky header, content, brand footer. */
@Component({
  selector: 'fm-public-layout',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterOutlet, PublicHeader, PublicFooter],
  template: `
    <fm-public-header />

    <main id="main-content">
      <router-outlet />
    </main>

    <fm-public-footer id="site-footer" />
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      min-height: 100dvh;
      background: var(--fm-paper);
      color: var(--fm-ink);
    }

    main {
      flex: 1;
    }
  `,
})
export class PublicLayout {}
