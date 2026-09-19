import {
  ChangeDetectionStrategy,
  Component,
  HostListener,
  inject,
  signal,
} from '@angular/core';
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
 * It was read-only, on the reasoning that editing someone's freight behind
 * their back produces a record neither party recognises. That concern is real
 * and the answer to it is attribution rather than refusal: support is asked to
 * fix a typo or pull a duplicate, and telling the operator to go and do it in
 * the database is worse on every count.
 *
 * So the writes exist, and every one of them is signed — `updated_by` carries
 * the admin who made the change, and a load posted on a shipper's behalf keeps
 * the admin in `created_by` while belonging to the shipper.
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

  /** The load awaiting a confirmed delete, or null. */
  protected readonly confirming = signal<AdminJob | null>(null);

  /**
   * Escape closes it.
   *
   * A confirmation you can only leave by finding the right button is a worse
   * confirmation: people click the wrong one to make it go away.
   */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (!this.removing()) {
      this.confirming.set(null);
    }
  }

  protected readonly removing = signal(false);

  /**
   * Remove a load.
   *
   * Asked for twice because it is not the operator's freight: somebody posted
   * it, and carriers may have enquired on it. The server soft-deletes, so the
   * record survives for those carriers and for dispute history — but the load
   * leaves the board either way, which is what the shipper will notice.
   */
  protected remove(job: AdminJob): void {
    this.removing.set(true);
    this.error.set(null);

    this.admin.deleteJob(job.id).subscribe({
      next: () => {
        this.removing.set(false);
        this.confirming.set(null);
        this.load();
      },
      error: (response) => {
        this.removing.set(false);
        this.confirming.set(null);
        this.error.set(describeError(response, 'Could not remove that load.'));
      },
    });
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
