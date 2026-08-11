import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';

import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import {
  featuresFor,
  SubscriptionPlan,
  SubscriptionState,
} from '../../subscription/subscription.models';
import { SubscriptionService } from '../../subscription/subscription.service';

/**
 * A carrier's own subscription: what is running, what is waiting on payment,
 * and how to change it.
 */
@Component({
  selector: 'fm-carrier-subscription',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon, Ripple],
  templateUrl: './subscription.html',
  styleUrl: './subscription.scss',
})
export class CarrierSubscription {
  protected readonly featuresFor = featuresFor;

  protected readonly state = signal<SubscriptionState | null>(null);
  protected readonly loading = signal(true);
  protected readonly busy = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly confirmingCancel = signal(false);

  private readonly subscriptions = inject(SubscriptionService);

  private readonly planData = toSignal(this.subscriptions.plans$, { initialValue: null });

  protected readonly plans = computed<SubscriptionPlan[]>(() => {
    const all = this.planData()?.items ?? [];
    const trialOffered = this.planData()?.trial_offer_open && this.state()?.trial.eligible;

    // The trial card only appears if this carrier could actually take it.
    return all.filter((plan) => !plan.is_trial || trialOffered);
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);

    this.subscriptions.state().subscribe({
      next: (state) => {
        this.state.set(state);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load your subscription.'));
      },
    });
  }

  protected startTrial(): void {
    this.busy.set('trial');
    this.error.set(null);
    this.notice.set(null);

    this.subscriptions.startTrial().subscribe({
      next: (response) => {
        this.busy.set(null);
        this.notice.set(response.message);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(null);
        this.error.set(describeError(response, 'Could not start your trial.'));
      },
    });
  }

  protected choose(plan: SubscriptionPlan): void {
    if (plan.is_trial) {
      this.startTrial();
      return;
    }

    this.busy.set(plan.code);
    this.error.set(null);
    this.notice.set(null);

    this.subscriptions.checkout(plan.code).subscribe({
      next: (response) => {
        const approvalUrl = response.data?.approval_url;

        // A redirect gateway takes over from here. `busy` is left set on
        // purpose: the page is about to be replaced, and re-enabling the
        // buttons first would invite a second click that starts a second order.
        if (approvalUrl) {
          window.location.href = approvalUrl;
          return;
        }

        this.busy.set(null);
        this.notice.set(response.message);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(null);
        this.error.set(describeError(response, 'Could not reserve that plan.'));
      },
    });
  }

  protected cancel(): void {
    const current = this.state()?.current;

    if (!current) {
      return;
    }

    this.busy.set('cancel');
    this.confirmingCancel.set(false);

    this.subscriptions.cancel(current.id).subscribe({
      next: (response) => {
        this.busy.set(null);
        this.notice.set(response.message);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(null);
        this.error.set(describeError(response, 'Could not cancel that subscription.'));
      },
    });
  }

  protected priceLabel(plan: SubscriptionPlan): string {
    return plan.is_trial ? 'Free' : `$${plan.price.toFixed(2)}`;
  }

  protected intervalLabel(plan: SubscriptionPlan): string {
    if (plan.is_trial) {
      return `for ${plan.interval_months} months`;
    }

    return plan.interval_months === 1 ? 'per month' : `every ${plan.interval_months} months`;
  }

  protected date(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-AU', { dateStyle: 'medium' }) : '';
  }
}
