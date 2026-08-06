import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'fm-register',
  imports: [ReactiveFormsModule, RouterLink],
  template: `
    <section class="auth-card">
      <h1>Create your account</h1>
      <p class="muted">Post freight or haul it — choose how you will use FreightMove.</p>

      <form [formGroup]="form" (ngSubmit)="submit()">
        <fieldset class="roles">
          <legend>I am a</legend>
          <label class="role">
            <input type="radio" formControlName="role" value="shipper" />
            <span><strong>Shipper</strong><small>I have freight to move</small></span>
          </label>
          <label class="role">
            <input type="radio" formControlName="role" value="carrier" />
            <span><strong>Carrier</strong><small>I have trucks and capacity</small></span>
          </label>
        </fieldset>

        <label>
          Full name
          <input type="text" formControlName="name" autocomplete="name" />
        </label>

        <label>
          Company name <span class="muted">(optional)</span>
          <input type="text" formControlName="company_name" autocomplete="organization" />
        </label>

        <label>
          Email
          <input type="email" formControlName="email" autocomplete="email" />
        </label>

        <label>
          Password
          <input type="password" formControlName="password" autocomplete="new-password" />
        </label>

        <label>
          Confirm password
          <input type="password" formControlName="password_confirmation" autocomplete="new-password" />
        </label>

        @if (error()) {
          <p class="error" role="alert">{{ error() }}</p>
        }

        @for (field of fieldErrors(); track field) {
          <p class="error">{{ field }}</p>
        }

        <button type="submit" [disabled]="form.invalid || busy()">
          {{ busy() ? 'Creating account…' : 'Create account' }}
        </button>
      </form>

      <p class="muted">Already registered? <a routerLink="/login">Sign in</a></p>
    </section>
  `,
  styleUrl: './register.scss',
})
export class Register {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly fieldErrors = signal<string[]>([]);

  protected readonly form = this.fb.nonNullable.group({
    role: ['shipper' as 'shipper' | 'carrier', [Validators.required]],
    name: ['', [Validators.required]],
    company_name: [''],
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', [Validators.required]],
  });

  protected submit(): void {
    if (this.form.invalid) {
      return;
    }

    this.busy.set(true);
    this.error.set(null);
    this.fieldErrors.set([]);

    this.auth.register(this.form.getRawValue()).subscribe({
      next: () => void this.router.navigateByUrl(this.auth.homeRoute()),
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(response.error?.message ?? 'Could not create the account.');
        // Laravel returns { errors: { field: [messages] } }.
        this.fieldErrors.set(Object.values(response.error?.errors ?? {}).flat() as string[]);
      },
    });
  }
}
