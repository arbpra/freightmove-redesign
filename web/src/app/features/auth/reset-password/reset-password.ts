import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import {
  AbstractControl,
  FormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { describeError } from '../../../core/http/describe-error';
import { Seo } from '../../../core/seo/seo.service';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';

/**
 * Choosing a new password from an emailed link.
 *
 * The token arrives in the path and the address in the query string, matching
 * the URL built by `ResetPassword::createUrlUsing` in AppServiceProvider. The
 * address is editable rather than hidden: if someone forwards the link or the
 * query string is stripped by a mail client, the page is still usable.
 */
@Component({
  selector: 'fm-reset-password',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './reset-password.html',
  styleUrl: './reset-password.scss',
})
export class ResetPassword {
  protected readonly busy = signal(false);
  protected readonly done = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly showPassword = signal(false);

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  protected readonly form = inject(FormBuilder).nonNullable.group(
    {
      email: [
        this.route.snapshot.queryParamMap.get('email') ?? '',
        [Validators.required, Validators.email],
      ],
      // Mirrors the API's Password::defaults(): at least 10 characters with
      // letters and numbers. The server is still the authority — this only
      // saves a round trip.
      password: ['', [Validators.required, Validators.minLength(10)]],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: ResetPassword.passwordsMatch },
  );

  constructor() {
    inject(Seo).apply({
      title: 'Choose a new password | FreightMove',
      description: 'Set a new password for your FreightMove account.',
      path: '/reset-password',
      // The URL carries a single-use token; it must never be indexed.
      noIndex: true,
    });
  }

  /** A link with no token cannot work, so the page says so rather than failing on submit. */
  protected readonly hasToken = this.token !== '';

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

    this.auth.resetPassword({ token: this.token, ...this.form.getRawValue() }).subscribe({
      next: () => {
        this.busy.set(false);
        this.done.set(true);

        // Straight to sign in, with the address filled in for them. Not signed
        // in automatically: whoever holds the link is not yet proven to be the
        // account holder beyond having read one email.
        setTimeout(() => void this.router.navigate(['/login']), 2500);
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(
          describeError(
            response,
            'That reset link is no longer valid. Please request a new one.',
          ),
        );
      },
    });
  }

  protected invalid(control: 'email' | 'password' | 'password_confirmation'): boolean {
    const field = this.form.controls[control];
    return field.invalid && field.touched;
  }

  /** True once both boxes have been filled and they disagree. */
  protected get mismatched(): boolean {
    return (
      this.form.hasError('mismatch') && this.form.controls.password_confirmation.touched
    );
  }

  private static passwordsMatch(group: AbstractControl): ValidationErrors | null {
    const password = group.get('password')?.value;
    const confirmation = group.get('password_confirmation')?.value;

    return password && confirmation && password !== confirmation ? { mismatch: true } : null;
  }
}
