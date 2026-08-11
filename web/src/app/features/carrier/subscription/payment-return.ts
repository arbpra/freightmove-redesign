import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { SubscriptionService } from '../../subscription/subscription.service';

/**
 * Where PayPal sends the carrier back to after they approve a payment.
 *
 * The browser only carries the order id — whether it was paid, and for how
 * much, is settled server-side against PayPal. This page therefore does not
 * "confirm" anything; it asks the API to, and reports what the API found.
 *
 * A failure here is deliberately not alarming: the webhook is the reliable
 * path, so a payment that went through will land shortly even if this leg
 * fails. The copy says so rather than telling someone their money vanished.
 */
@Component({
  selector: 'fm-payment-return',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Ripple],
  templateUrl: './payment-return.html',
  styleUrl: './payment-return.scss',
})
export class PaymentReturn {
  protected readonly state = signal<'working' | 'done' | 'pending' | 'missing'>('working');
  protected readonly message = signal<string | null>(null);
  protected readonly endsOn = signal<string | null>(null);

  private readonly subscriptions = inject(SubscriptionService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  constructor() {
    // PayPal returns `?token=<order id>`; older integrations use `orderID`.
    const params = this.route.snapshot.queryParamMap;
    const reference = params.get('token') ?? params.get('orderID');

    if (!reference) {
      this.state.set('missing');
      return;
    }

    this.subscriptions.capture(reference).subscribe({
      next: (response) => {
        this.state.set('done');
        this.message.set(response.message);
        this.endsOn.set(response.data?.ends_on ?? null);
      },
      error: (response: HttpErrorResponse) => {
        // 422 means PayPal has not completed it — which the webhook may still
        // resolve. Anything else is a genuine problem worth naming.
        this.state.set(response.status === 422 ? 'pending' : 'missing');
        this.message.set(
          describeError(response, 'We could not confirm that payment just yet.'),
        );
      },
    });
  }

  protected backToSubscription(): void {
    void this.router.navigate(['/carrier/subscription']);
  }

  protected date(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-AU', { dateStyle: 'medium' }) : '';
  }
}
