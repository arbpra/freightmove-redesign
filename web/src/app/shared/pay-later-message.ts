import { HttpClient } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  input,
  signal,
  viewChild,
  effect,
} from '@angular/core';

import { environment } from '../../environments/environment';
import { ApiEnvelope } from '../core/auth/auth.models';

interface ClientConfig {
  paypal_client_id: string | null;
  paypal_mode: string | null;
}

/**
 * PayPal's Pay Later message: "Pay in 4 interest-free payments of $174.98".
 *
 * Rendered by PayPal's own script, not by us, and that is the point — the
 * instalment terms, the amount, the wording and the eligibility rules are
 * PayPal's to state. Writing our own version would mean publishing credit
 * terms we do not set and cannot keep current.
 *
 * **It renders nothing unless everything lines up.** No client id, the gateway
 * not on PayPal, the buyer's country or the merchant account not eligible —
 * each of those ends with an empty element rather than a broken one. That is
 * what makes it safe to ship before Pay Later has been confirmed available:
 * the failure mode is silence.
 *
 * The script is loaded once for the page and shared. PayPal's SDK registers
 * globals and re-running it with different parameters replaces them, so a
 * second copy would fight the first.
 */
@Component({
  selector: 'fm-pay-later-message',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <!-- PayPal replaces this element's contents. Empty and zero-height until
         it does, so an ineligible viewer sees no gap. -->
    <div
      #slot
      data-pp-message
      data-pp-style-layout="text"
      data-pp-style-logo-type="inline"
      data-pp-style-text-size="12"
      [attr.data-pp-amount]="amount()"
    ></div>
  `,
  styles: `
    :host {
      display: block;
    }

    /* Nothing to show is nothing to space. */
    :host:has(div:empty) {
      display: none;
    }
  `,
})
export class PayLaterMessage {
  /** The price the instalments are calculated from. */
  readonly amount = input.required<number>();

  private readonly slot = viewChild.required<ElementRef<HTMLElement>>('slot');
  private readonly http = inject(HttpClient);
  private readonly ready = signal(false);

  /** One script per page, shared by every instance. */
  private static loader: Promise<boolean> | null = null;

  constructor() {
    this.load();

    effect(() => {
      // PayPal only reads the attributes when it renders, so a later amount
      // change needs an explicit re-render.
      if (this.ready() && this.amount()) {
        this.render();
      }
    });
  }

  private load(): void {
    PayLaterMessage.loader ??= this.http
      .get<ApiEnvelope<ClientConfig>>(`${environment.apiUrl}/public/config`)
      .toPromise()
      .then((response) => {
        const clientId = response?.data?.paypal_client_id;

        // No key, or PayPal is not the active gateway. Nothing to advertise.
        return clientId ? this.injectScript(clientId) : false;
      })
      .catch(() => false);

    PayLaterMessage.loader.then((ok) => this.ready.set(ok));
  }

  private injectScript(clientId: string): Promise<boolean> {
    return new Promise((resolve) => {
      const existing = document.querySelector<HTMLScriptElement>('script[data-fm-paypal]');

      if (existing) {
        resolve(true);

        return;
      }

      const script = document.createElement('script');

      // `components=messages` loads only the messaging piece — not the
      // buttons — so this cannot interfere with the redirect checkout.
      script.src =
        'https://www.paypal.com/sdk/js' +
        `?client-id=${encodeURIComponent(clientId)}` +
        '&components=messages&currency=AUD';
      script.async = true;
      script.dataset['fmPaypal'] = 'true';

      // A blocked script, an ad blocker or an offline visitor all land here,
      // and all of them simply mean "no message".
      script.onerror = () => resolve(false);
      script.onload = () => resolve(true);

      document.head.appendChild(script);
    });
  }

  private render(): void {
    const paypal = (window as unknown as { paypal?: { Messages?: (o: unknown) => { render: (el: Element) => void } } })
      .paypal;

    if (!paypal?.Messages) {
      return;
    }

    try {
      paypal.Messages({ amount: this.amount() }).render(this.slot().nativeElement);
    } catch {
      // Ineligible buyer, unsupported country, or a merchant account without
      // Pay Later. All of them are "no message", never a broken page.
    }
  }
}
