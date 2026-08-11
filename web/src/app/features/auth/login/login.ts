import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { describeError } from '../../../core/http/describe-error';
import { Seo } from '../../../core/seo/seo.service';
import { CONTACT_PHONE, CONTACT_PHONE_HREF } from '../../../layout/public-nav';
import { Icon } from '../../../shared/icon';
import { IconName } from '../../../shared/icons';
import { Ripple } from '../../../shared/ripple.directive';

@Component({
  selector: 'fm-login',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  protected readonly phone = CONTACT_PHONE;
  protected readonly phoneHref = CONTACT_PHONE_HREF;

  protected readonly assurances: { icon: IconName; title: string; body: string }[] = [
    {
      icon: 'truck-fast',
      title: 'Your loads, in one place',
      body: 'Every job, quote and message since you joined — nothing lost in email.',
    },
    {
      icon: 'badge-check',
      title: 'Verified carriers only',
      body: 'ABN, insurance and credentials checked before anyone can quote.',
    },
    {
      icon: 'lock',
      title: 'Encrypted end to end',
      body: 'Your data and transactions are never shared with third parties.',
    },
  ];

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly showPassword = signal(false);

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
  });

  constructor() {
    inject(Seo).apply({
      title: 'Log in | FreightMove',
      description:
        'Log in to your FreightMove account to post loads, compare quotes from verified Australian carriers, and track freight in transit.',
      path: '/login',
    });
  }

  protected togglePassword(): void {
    this.showPassword.update((shown) => !shown);
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.busy.set(true);
    this.error.set(null);

    this.auth.login(this.form.getRawValue()).subscribe({
      next: () => {
        const redirect = this.route.snapshot.queryParamMap.get('redirect');
        void this.router.navigateByUrl(redirect ?? this.auth.homeRoute());
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(describeError(response, 'Could not sign in. Please try again.'));
      },
    });
  }

  /** True once a control has been touched and is failing validation. */
  protected invalid(control: 'email' | 'password'): boolean {
    const field = this.form.controls[control];
    return field.invalid && field.touched;
  }
}
