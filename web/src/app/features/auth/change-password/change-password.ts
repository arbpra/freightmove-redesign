import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { describeError, fieldErrors } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';

/**
 * Set a new password.
 *
 * Reached from the migration prompt shown to accounts brought over from
 * freightmove.au, and usable any time from account settings. Never gates
 * anything — a customer who ignores it keeps working normally.
 */
@Component({
  selector: 'fm-change-password',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './change-password.html',
  styleUrl: './change-password.scss',
})
export class ChangePassword {
  protected readonly auth = inject(AuthService);

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly details = signal<string[]>([]);
  protected readonly done = signal(false);
  protected readonly reveal = signal(false);

  private readonly router = inject(Router);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    current_password: ['', [Validators.required]],
    password: ['', [Validators.required, Validators.minLength(10)]],
    password_confirmation: ['', [Validators.required]],
  });

  protected toggleReveal(): void {
    this.reveal.update((shown) => !shown);
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const { password, password_confirmation } = this.form.getRawValue();

    if (password !== password_confirmation) {
      this.error.set('Those passwords do not match.');
      return;
    }

    this.busy.set(true);
    this.error.set(null);
    this.details.set([]);

    this.auth.changePassword(this.form.getRawValue()).subscribe({
      next: () => {
        this.busy.set(false);
        this.done.set(true);
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(describeError(response, 'Could not update your password.'));
        this.details.set(fieldErrors(response));
      },
    });
  }

  protected finish(): void {
    void this.router.navigateByUrl(this.auth.homeRoute());
  }

  protected invalid(control: keyof typeof this.form.controls): boolean {
    const field = this.form.controls[control];
    return field.invalid && field.touched;
  }
}
