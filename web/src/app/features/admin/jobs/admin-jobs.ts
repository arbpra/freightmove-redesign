import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { AdminJob, AdminService, Paged } from '../admin.service';
import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';

/**
 * Load oversight.
 *
 * The endpoint has existed since the admin console was built and nothing ever
 * called it — there was no screen, so the only way to look at a load as an
 * admin was to query the database.
 *
 * Read-only, like the rest of the console. Editing someone's freight behind
 * their back produces a record neither party recognises, which is why the API
 * offers no admin write for it either.
 */
@Component({
  selector: 'fm-admin-jobs',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, RouterLink, Icon],
  templateUrl: './admin-jobs.html',
  styleUrl: './admin-jobs.scss',
})
export class AdminJobs {
  protected readonly filters = [
    { value: '', label: 'All loads' },
    { value: 'published', label: 'Open' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'completed', label: 'Completed' },
    { value: 'draft', label: 'Draft' },
    { value: 'cancelled', label: 'Cancelled' },
  ];

  protected readonly page = signal<Paged<AdminJob> | null>(null);
  protected readonly status = signal('');
  protected readonly search = signal('');
  protected readonly pageNumber = signal(1);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  private readonly admin = inject(AdminService);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.admin
      .jobs({
        status: this.status() || undefined,
        search: this.search().trim() || undefined,
        page: this.pageNumber(),
      })
      .subscribe({
        next: (page) => {
          this.page.set(page);
          this.loading.set(false);
        },
        error: (response) => {
          this.loading.set(false);
          this.error.set(describeError(response, 'Could not load the loads.'));
        },
      });
  }

  /** Any filter change resets to page one — page 4 of a new filter is nothing. */
  protected filterBy(status: string): void {
    this.status.set(status);
    this.pageNumber.set(1);
    this.load();
  }

  protected runSearch(): void {
    this.pageNumber.set(1);
    this.load();
  }

  protected goTo(page: number): void {
    this.pageNumber.set(page);
    this.load();
  }

  /** The board's opaque reference for a load id: 443 -> FM-000443. */
  protected ref(id: number): string {
    return `FM-${String(id).padStart(6, '0')}`;
  }

  protected date(iso: string | null): string {
    return iso ? new Date(iso).toLocaleDateString('en-AU') : '—';
  }
}
