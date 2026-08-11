import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import {
  JOB_STATUS_LABEL,
  RELISTABLE_STATUSES,
  FreightJob,
  JobStatus,
  Paginated,
} from './job.models';
import { JobService } from './job.service';

@Component({
  selector: 'fm-job-list',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, FormsModule, Icon],
  templateUrl: './job-list.html',
  styleUrl: './job-list.scss',
})
export class JobList {
  protected readonly statusLabel = JOB_STATUS_LABEL;

  protected readonly filters: { value: JobStatus | ''; label: string }[] = [
    { value: '', label: 'All' },
    { value: 'draft', label: 'Drafts' },
    { value: 'published', label: 'Live' },
    { value: 'quoted', label: 'Quoted' },
    { value: 'accepted', label: 'Booked' },
    { value: 'completed', label: 'Completed' },
  ];

  protected readonly page = signal<Paginated<FreightJob> | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly status = signal<JobStatus | ''>('');
  protected readonly search = signal('');
  protected readonly busyId = signal<number | null>(null);
  protected readonly notice = signal<string | null>(null);

  private readonly jobs = inject(JobService);

  constructor() {
    this.load();
  }

  protected load(page = 1): void {
    this.loading.set(true);
    this.error.set(null);

    this.jobs.list({ status: this.status(), search: this.search(), page }).subscribe({
      next: (result) => {
        this.page.set(result);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load your loads.'));
      },
    });
  }

  protected filterBy(status: JobStatus | ''): void {
    this.status.set(status);
    this.load();
  }

  protected onSearch(): void {
    this.load();
  }

  protected publish(job: FreightJob): void {
    this.jobs.publish(job.id).subscribe({
      next: () => this.load(this.page()?.meta.current_page ?? 1),
      error: (response) => this.error.set(describeError(response, 'Could not publish that load.')),
    });
  }

  /** Signs a booked load off as delivered, which opens reviews for both sides. */
  protected complete(job: FreightJob): void {
    this.busyId.set(job.id);
    this.error.set(null);
    this.notice.set(null);

    this.jobs.complete(job.id).subscribe({
      next: (response) => {
        this.busyId.set(null);
        this.notice.set(response.message || 'Load marked complete.');
        this.load(this.page()?.meta.current_page ?? 1);
      },
      error: (response) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not complete that load.'));
      },
    });
  }

  /** Only a load that is actually on the board can be bumped up it. */
  protected canRelist(job: FreightJob): boolean {
    return RELISTABLE_STATUSES.includes(job.status);
  }

  /**
   * Bumps a load back to the top of the carrier board.
   *
   * Inside the cooldown the API answers 429 with a message naming the next
   * eligible time, so that is shown as guidance rather than as a failure.
   */
  protected relist(job: FreightJob): void {
    this.busyId.set(job.id);
    this.error.set(null);
    this.notice.set(null);

    this.jobs.relist(job.id).subscribe({
      next: (response) => {
        this.busyId.set(null);
        this.notice.set(response.message || 'Load bumped back to the top of the board.');
        this.load(this.page()?.meta.current_page ?? 1);
      },
      error: (response) => {
        this.busyId.set(null);

        if (response?.status === 429) {
          this.notice.set(describeError(response, 'This load was bumped recently.'));
          return;
        }

        this.error.set(describeError(response, 'Could not bump that load.'));
      },
    });
  }

  /** "Brisbane, QLD → Perth, WA" */
  protected lane(job: FreightJob): string {
    return `${job.pickup_location} → ${job.delivery_location}`;
  }
}
