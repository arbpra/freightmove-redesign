import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { DocumentStatus } from '../../carrier/profile/profile.models';
import { QueuePage, QueuedDocument, VerificationQueueService } from './verifications.service';

/**
 * The admin verification queue.
 *
 * Oldest first, because it is a queue: the carrier who has waited longest is
 * dealt with first. Rejecting opens a note box rather than acting immediately —
 * the API requires a reason, and a rejection a carrier cannot act on just
 * produces the same document again next week.
 */
@Component({
  selector: 'fm-admin-verifications',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon, Ripple],
  templateUrl: './verifications.html',
  styleUrl: './verifications.scss',
})
export class AdminVerifications {
  protected readonly filters: { value: DocumentStatus; label: string }[] = [
    { value: 'pending', label: 'Awaiting review' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
  ];

  protected readonly page = signal<QueuePage | null>(null);
  protected readonly status = signal<DocumentStatus>('pending');
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);

  protected readonly busyId = signal<number | null>(null);
  protected readonly rejecting = signal<QueuedDocument | null>(null);
  protected readonly rejectNote = signal('');

  private readonly queue = inject(VerificationQueueService);

  constructor() {
    this.load();
  }

  protected load(page = 1): void {
    this.loading.set(true);
    this.error.set(null);

    this.queue.list(this.status(), page).subscribe({
      next: (result) => {
        this.page.set(result);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load the queue.'));
      },
    });
  }

  protected filterBy(status: DocumentStatus): void {
    this.status.set(status);
    this.load();
  }

  protected approve(doc: QueuedDocument): void {
    this.busyId.set(doc.id);
    this.error.set(null);

    this.queue.approve(doc.id).subscribe({
      next: (response) => {
        this.busyId.set(null);
        this.notice.set(
          `${doc.carrier.company_name ?? doc.carrier.name}: document approved — ` +
            `now ${response.data.carrier_verification_status}` +
            (response.data.still_missing.length
              ? `, still needs ${response.data.still_missing.join(', ')}.`
              : '.'),
        );
        this.load(this.page()?.meta.current_page ?? 1);
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not approve that document.'));
      },
    });
  }

  protected startReject(doc: QueuedDocument): void {
    this.rejecting.set(doc);
    this.rejectNote.set('');
  }

  protected cancelReject(): void {
    this.rejecting.set(null);
  }

  protected confirmReject(): void {
    const doc = this.rejecting();

    if (!doc) {
      return;
    }

    this.busyId.set(doc.id);

    this.queue.reject(doc.id, this.rejectNote()).subscribe({
      next: () => {
        this.busyId.set(null);
        this.rejecting.set(null);
        this.notice.set('Document rejected. The carrier can see your note.');
        this.load(this.page()?.meta.current_page ?? 1);
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not reject that document.'));
      },
    });
  }

  /** Opens the file. It sits behind a policy, so it travels as a blob. */
  protected view(doc: QueuedDocument): void {
    this.busyId.set(doc.id);

    this.queue.download(doc.id).subscribe({
      next: (blob) => {
        this.busyId.set(null);

        const url = URL.createObjectURL(blob);
        const link = window.document.createElement('a');
        link.href = url;
        link.download = doc.original_name ?? 'document';
        link.click();
        URL.revokeObjectURL(url);
      },
      error: (response: HttpErrorResponse) => {
        this.busyId.set(null);
        this.error.set(describeError(response, 'Could not open that document.'));
      },
    });
  }

  protected fileSize(bytes: number | null): string {
    if (!bytes) {
      return '';
    }

    return bytes < 1024 * 1024
      ? `${Math.round(bytes / 1024)} KB`
      : `${Math.round((bytes / 1024 / 1024) * 10) / 10} MB`;
  }

  protected waitedDays(uploadedAt: string | null): number {
    if (!uploadedAt) {
      return 0;
    }

    return Math.floor((Date.now() - new Date(uploadedAt).getTime()) / 86400000);
  }
}
