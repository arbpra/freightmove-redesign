import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';

import { describeError, fieldErrors } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { AdminService, AdminUserDetail } from '../admin.service';

/**
 * One account, as an admin sees and edits it.
 *
 * The support calls this exists for are specific: a shipper who mistyped their
 * email at registration cannot receive the reset link that would let them fix
 * it, and a carrier who has lost the mailbox their account was registered with
 * cannot recover it at all.
 *
 * **What it deliberately cannot do**, matching the API:
 *
 *   - change a role — that is the privilege boundary the whole authorisation
 *     layer rests on, and an edit form with a role dropdown is how it gets
 *     crossed by accident;
 *   - change a status — suspending has its own action, with its own refusals;
 *   - touch another admin's account, in any way.
 *
 * Setting a password is the one genuinely dangerous action here: it hands over
 * an account, private conversations included. So it is kept apart from the
 * details form, states what it will do before it does it, and always signs the
 * account out everywhere — a reset that leaves live tokens behind has not
 * taken the account back from whoever had it.
 */
@Component({
  selector: 'fm-admin-user-detail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './admin-user-detail.html',
  styleUrl: './admin-user-detail.scss',
})
export class AdminUserDetailPage {
  protected readonly user = signal<AdminUserDetail | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly details = signal<string[]>([]);

  protected readonly savingDetails = signal(false);
  protected readonly savingPassword = signal(false);
  protected readonly saved = signal<string | null>(null);

  /** The password box stays shut until it is deliberately opened. */
  protected readonly passwordOpen = signal(false);

  private readonly admin = inject(AdminService);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  protected readonly form = this.fb.nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(255)]],
    first_name: ['', [Validators.maxLength(100)]],
    last_name: ['', [Validators.maxLength(100)]],
    email: ['', [Validators.required, Validators.email, Validators.maxLength(255)]],
    phone: ['', [Validators.maxLength(32)]],
    company_name: ['', [Validators.maxLength(255)]],
    abn_acn: ['', [Validators.maxLength(32)]],
    address_line_1: ['', [Validators.maxLength(255)]],
    city: ['', [Validators.maxLength(120)]],
    state: ['', [Validators.maxLength(60)]],
    postal_code: ['', [Validators.maxLength(16)]],
  });

  protected readonly passwordForm = this.fb.nonNullable.group({
    password: ['', [Validators.required, Validators.minLength(8)]],
    reason: ['', [Validators.maxLength(500)]],
  });

  /** Another admin is read-only here, exactly as the API enforces. */
  protected readonly editable = computed(() => {
    const user = this.user();

    return user !== null && user.role !== 'admin';
  });

  constructor() {
    const id = Number(this.route.snapshot.paramMap.get('id'));

    this.admin.user(id).subscribe({
      next: (response) => {
        this.loading.set(false);
        this.fill(response.data);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not open that account.'));
      },
    });
  }

  protected saveDetails(): void {
    const user = this.user();

    if (!user || this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const value = this.form.getRawValue();

    this.savingDetails.set(true);
    this.error.set(null);
    this.details.set([]);
    this.saved.set(null);

    this.admin
      .updateUser(user.id, {
        name: value.name,
        first_name: value.first_name || null,
        last_name: value.last_name || null,
        email: value.email,
        phone: value.phone || null,
        profile: {
          company_name: value.company_name || null,
          abn_acn: value.abn_acn || null,
          address_line_1: value.address_line_1 || null,
          city: value.city || null,
          state: value.state || null,
          postal_code: value.postal_code || null,
        },
      })
      .subscribe({
        next: (response) => {
          this.savingDetails.set(false);
          this.fill(response.data);
          this.saved.set('Details saved.');
        },
        error: (response: HttpErrorResponse) => {
          this.savingDetails.set(false);
          this.error.set(describeError(response, 'Could not save those details.'));
          this.details.set(fieldErrors(response));
        },
      });
  }

  protected savePassword(): void {
    const user = this.user();

    if (!user || this.passwordForm.invalid) {
      this.passwordForm.markAllAsTouched();
      return;
    }

    const { password, reason } = this.passwordForm.getRawValue();

    this.savingPassword.set(true);
    this.error.set(null);
    this.details.set([]);
    this.saved.set(null);

    this.admin.setUserPassword(user.id, password, reason || undefined).subscribe({
      next: (response) => {
        this.savingPassword.set(false);
        this.fill(response.data);
        this.passwordForm.reset();
        this.passwordOpen.set(false);
        this.saved.set('Password set. The account has been signed out everywhere.');
      },
      error: (response: HttpErrorResponse) => {
        this.savingPassword.set(false);
        this.error.set(describeError(response, 'Could not set that password.'));
        this.details.set(fieldErrors(response));
      },
    });
  }

  /**
   * A password an admin reads off the screen and types into a phone call.
   *
   * Ambiguous characters are left out for that reason — 0/O and 1/l/I are
   * where a dictated password goes wrong.
   */
  protected suggestPassword(): void {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = crypto.getRandomValues(new Uint32Array(16));
    const password = Array.from(bytes, (n) => alphabet[n % alphabet.length]).join('');

    this.passwordForm.controls.password.setValue(password);
  }

  protected invalid(control: keyof typeof this.form.controls): boolean {
    const field = this.form.controls[control];

    return field.invalid && field.touched;
  }

  protected date(iso: string | null): string {
    return iso
      ? new Date(iso).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' })
      : '—';
  }

  private fill(user: AdminUserDetail): void {
    this.user.set(user);

    this.form.patchValue({
      name: user.name ?? '',
      first_name: user.first_name ?? '',
      last_name: user.last_name ?? '',
      email: user.email ?? '',
      phone: user.phone ?? '',
      company_name: user.profile?.company_name ?? '',
      abn_acn: user.profile?.abn_acn ?? '',
      address_line_1: user.profile?.address_line_1 ?? '',
      city: user.profile?.city ?? '',
      state: user.profile?.state ?? '',
      postal_code: user.profile?.postal_code ?? '',
    });

    if (!this.editable()) {
      this.form.disable();
    }
  }
}
