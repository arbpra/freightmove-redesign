import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { AccountStatus, AdminService, AdminUser, Paged } from '../admin.service';

/**
 * Account oversight.
 *
 * Suspending is the only write. Role changes are deliberately not offered —
 * role is the privilege boundary the whole authorisation layer rests on, and an
 * "edit user" form with a role dropdown is how that gets crossed by accident.
 */
@Component({
  selector: 'fm-admin-users',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './admin-users.html',
  styleUrl: './admin-users.scss',
})
export class AdminUsers {
  protected readonly roles = [
    { value: '', label: 'Everyone' },
    { value: 'shipper', label: 'Shippers' },
    { value: 'carrier', label: 'Carriers' },
    { value: 'admin', label: 'Admins' },
  ];

  protected readonly page = signal<Paged<AdminUser> | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly busyId = signal<number | null>(null);

  protected readonly role = signal('');
  protected readonly status = signal('');
  protected readonly legacyOnly = signal(false);
  protected readonly search = signal('');

  /** The account awaiting confirmation before it is suspended. */
  protected readonly confirming = signal<AdminUser | null>(null);

  private readonly admin = inject(AdminService);

  constructor() {
    this.load();
  }

  protected load(page = 1): void {
    this.loading.set(true);
    this.error.set(null);

    this.admin
      .users({
        role: this.role(),
        status: this.status(),
        search: this.search(),
        legacy: this.legacyOnly() ? '1' : '',
        page,
      })
      .subscribe({
        next: (result) => {
          this.page.set(result);
          this.loading.set(false);
        },
        error: (response: HttpErrorResponse) => {
          this.loading.set(false);
          this.error.set(describeError(response, 'Could not load accounts.'));
        },
      });
  }

  protected filter(): void {
    this.load();
  }

  protected toggleLegacy(): void {
    this.legacyOnly.update((on) => !on);
    this.load();
  }

  /** Suspending signs someone out everywhere, so it asks first. */
  protected confirmSuspend(user: AdminUser): void {
    this.confirming.set(user);
  }

  protected cancelConfirm(): void {
    this.confirming.set(null);
  }

  protected setStatus(user: AdminUser, status: AccountStatus): void {
    this.busyId.set(user.id);
    this.error.set(null);
    this.notice.set(null);
    this.confirming.set(null);

    this.admin.setStatus(user.id, status).subscribe({
      next: (response) => {
        this.busyId.set(null);
        this.notice.set(response.message);
        this.load(this.page()?.meta.current_page ?? 1);
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not change that account.'));
      },
    });
  }

  protected joined(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-AU') : '';
  }
}
