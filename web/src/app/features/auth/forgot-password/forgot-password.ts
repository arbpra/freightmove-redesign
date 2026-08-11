import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { describeError } from '../../../core/http/describe-error';
import { Seo } from '../../../core/seo/seo.service';
import { CONTACT_PHONE, CONTACT_PHONE_HREF } from '../../../layout/public-nav';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';

/**
 * "Send me a reset link."
 *
 * The API answers identically whether or not the address is registered, so
 * that an anonymous caller cannot use this page to discover who has an account.
 * The confirmation here is worded to match — "if that address is registered" —
 * rather than implying an email definitely went.
 */
@Component({
  selector: 'fm-forgot-password',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './forgot-password.html',
  styleUrl: './forgot-password.scss',
})
export class ForgotPassword {
  protected readonly phone = CONTACT_PHONE;
  protected readonly phoneHref = CONTACT_PHONE_HREF;

  protected readonly busy = signal(false);
  protected readonly sent = signal(false);
  protected readonly error = signal<string | null>(null);

  private readonly auth = inject(AuthService);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
  });

  constructor() {
    inject(Seo).apply({
      title: 'Reset your password | FreightMove',
      description:
        'Forgotten your FreightMove password? Enter your email address and we will send you a link to choose a new one.',
      path: '/forgot-password',
      // Nothing here belongs in search results.
      noIndex: true,
    });
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.busy.set(true);
    this.error.set(null);

    this.auth.forgotPassword(this.form.getRawValue().email).subscribe({
      next: () => {
        this.busy.set(false);
        this.sent.set(true);
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(
          describeError(response, 'We could not send that link just now. Please try again.'),
        );
      },
    });
  }

  protected invalid(): boolean {
    const field = this.form.controls.email;
    return field.invalid && field.touched;
  }
}
