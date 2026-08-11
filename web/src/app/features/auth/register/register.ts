import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { describeError, fieldErrors } from '../../../core/http/describe-error';
import { Seo } from '../../../core/seo/seo.service';
import { Icon } from '../../../shared/icon';
import { IconName } from '../../../shared/icons';
import { Ripple } from '../../../shared/ripple.directive';
import { SubscriptionPlan } from '../../subscription/subscription.models';
import { SubscriptionService } from '../../subscription/subscription.service';

type Role = 'shipper' | 'carrier';

@Component({
  selector: 'fm-register',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './register.html',
  styleUrl: './register.scss',
})
export class Register {
  /**
   * Bound from the `?role=` query param so the "Post a Load" and "Find Loads"
   * calls to action on the marketing pages land on the right side of the form.
   */
  readonly role = input<string | undefined>();

  protected readonly roles: { value: Role; label: string; blurb: string; icon: IconName }[] = [
    // Kept short and symmetric so neither card wraps to a second line.
    {
      value: 'shipper',
      label: 'I have freight',
      blurb: 'Post loads and compare quotes',
      icon: 'boxes',
    },
    {
      value: 'carrier',
      label: 'I have trucks',
      blurb: 'Find loads on your lanes',
      icon: 'truck-fast',
    },
  ];

  /** Selling points swap with the chosen role so the panel stays relevant. */
  protected readonly benefits = computed<{ icon: IconName; title: string; body: string }[]>(() =>
    this.selected() === 'carrier'
      ? [
          {
            icon: 'truck-fast',
            title: 'Loads on your lanes',
            body: 'Thousands of active jobs Australia wide, filtered to the runs you already do.',
          },
          {
            icon: 'price-tag',
            title: 'Quote on your terms',
            body: 'You set the price and the pickup window. No forced rates, no lead fees.',
          },
          {
            icon: 'badge-check',
            title: 'Get verified once',
            body: 'One check on ABN and insurance, then quote on everything that fits.',
          },
        ]
      : [
          {
            icon: 'price-tag',
            title: 'Competing quotes, free',
            body: 'Post once and let verified carriers bid. Most loads get a quote within the hour.',
          },
          {
            icon: 'shield-check',
            title: 'Every carrier checked',
            body: 'ABN, insurance and operating credentials verified before anyone can quote.',
          },
          {
            icon: 'thumbs-up',
            title: 'No obligation',
            body: 'Compare freely and book only when the number and the carrier both look right.',
          },
        ],
  );

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly fieldErrors = signal<string[]>([]);
  protected readonly showPassword = signal(false);

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    role: ['shipper' as Role, [Validators.required]],
    name: ['', [Validators.required]],
    company_name: [''],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', [Validators.required]],
    // Carriers only. Validity is enforced by the role-aware check in submit()
    // rather than a static validator, since the requirement appears and
    // disappears with the chosen role.
    subscription_plan: [''],
  });

  /** Mirrors the role control so the template and benefits react to changes. */
  protected readonly selected = signal<Role>('shipper');

  private readonly subscriptions = inject(SubscriptionService);

  /** The plans a signing-up carrier may pick, trial included while it is open. */
  protected readonly plans = toSignal(
    this.subscriptions.plans$.pipe(
      map((list) =>
        list.trial_offer_open ? list.items : list.items.filter((plan) => !plan.is_trial),
      ),
    ),
    { initialValue: [] as SubscriptionPlan[] },
  );

  protected readonly chosenPlan = signal('');

  constructor() {
    inject(Seo).apply({
      title: 'Create your free FreightMove account',
      description:
        'Join FreightMove free. Shippers post loads and compare quotes from verified Australian carriers; carriers find loads on the lanes they already run.',
      path: '/register',
    });

    effect(() => {
      const role = this.role();
      if (role === 'shipper' || role === 'carrier') {
        this.choose(role);
      }
    });

    this.form.controls.role.valueChanges.subscribe((role) => this.selected.set(role));
  }

  protected choose(role: Role): void {
    this.form.controls.role.setValue(role);

    // Switching to shipper clears any plan already picked: the API rejects the
    // field outright for shippers rather than ignoring it, so leaving a stale
    // value behind would fail the submit with a confusing error.
    if (role !== 'carrier') {
      this.choosePlan('');
    }
  }

  protected choosePlan(code: string): void {
    this.chosenPlan.set(code);
    this.form.controls.subscription_plan.setValue(code);
  }

  protected planPrice(plan: SubscriptionPlan): string {
    return plan.is_trial ? 'Free' : `$${plan.price.toFixed(2)}`;
  }

  protected planInterval(plan: SubscriptionPlan): string {
    if (plan.is_trial) {
      return `for ${plan.interval_months} months`;
    }

    return plan.interval_months === 1 ? 'per month' : `every ${plan.interval_months} months`;
  }

  protected togglePassword(): void {
    this.showPassword.update((shown) => !shown);
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    if (this.form.controls.password.value !== this.form.controls.password_confirmation.value) {
      this.error.set('Those passwords do not match.');
      return;
    }

    const raw = this.form.getRawValue();

    if (raw.role === 'carrier' && !raw.subscription_plan) {
      this.error.set('Choose a plan to get started.');
      return;
    }

    // The API rejects `subscription_plan` for shippers rather than ignoring it,
    // so the key is omitted entirely rather than sent empty.
    const { subscription_plan, ...rest } = raw;
    const payload = raw.role === 'carrier' ? { ...rest, subscription_plan } : rest;

    this.busy.set(true);
    this.error.set(null);
    this.fieldErrors.set([]);

    this.auth.register(payload).subscribe({
      next: () => void this.router.navigateByUrl(this.auth.homeRoute()),
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(describeError(response, 'Could not create the account.'));
        this.fieldErrors.set(fieldErrors(response));
      },
    });
  }

  protected invalid(control: 'name' | 'email' | 'password' | 'password_confirmation'): boolean {
    const field = this.form.controls[control];
    return field.invalid && field.touched;
  }
}
