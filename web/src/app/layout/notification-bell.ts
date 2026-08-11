import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';

import { AuthService } from '../core/auth/auth.service';
import {
  AppNotification,
  NotificationService,
} from '../core/notifications/notification.service';
import { Icon } from '../shared/icon';

/**
 * The topbar bell.
 *
 * The panel loads on open rather than on page load: most sessions never open
 * it, and the badge already answers the only question most people have.
 */
@Component({
  selector: 'fm-notification-bell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <button
      type="button"
      class="bell"
      (click)="toggle()"
      [attr.aria-expanded]="open()"
      [attr.aria-label]="
        count() > 0 ? count() + ' unread notifications' : 'Notifications'
      "
    >
      <fm-icon name="bell" size="17" />
      @if (count() > 0) {
        <span class="badge" aria-hidden="true">{{ count() > 9 ? '9+' : count() }}</span>
      }
    </button>

    @if (open()) {
      <div class="scrim" (click)="close()" aria-hidden="true"></div>

      <div class="panel" role="dialog" aria-label="Notifications">
        <header>
          <strong>Notifications</strong>
          @if (count() > 0) {
            <button type="button" class="linky" (click)="markAllRead()">Mark all read</button>
          }
        </header>

        @if (loading()) {
          <p class="state">Loading…</p>
        } @else if (items().length === 0) {
          <p class="state">Nothing yet. Quotes and decisions will show up here.</p>
        } @else {
          <ul>
            @for (note of items(); track note.id) {
              <li [class.unread]="!note.is_read">
                <button type="button" (click)="openNotification(note)">
                  <span class="title">{{ note.title }}</span>
                  @if (note.body) {
                    <span class="body">{{ note.body }}</span>
                  }
                  <span class="when">{{ relative(note.created_at) }}</span>
                </button>
              </li>
            }
          </ul>
        }
      </div>
    }
  `,
  styleUrl: './notification-bell.scss',
})
export class NotificationBell {
  private readonly notifications = inject(NotificationService);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);

  protected readonly count = this.notifications.unreadCount;
  protected readonly open = signal(false);
  protected readonly loading = signal(false);
  protected readonly items = signal<AppNotification[]>([]);

  protected toggle(): void {
    const next = !this.open();
    this.open.set(next);

    if (next) {
      this.load();
    }
  }

  protected close(): void {
    this.open.set(false);
  }

  private load(): void {
    this.loading.set(true);

    this.notifications.list().subscribe({
      next: (feed) => {
        this.items.set(feed.items);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  protected markAllRead(): void {
    this.notifications.markAllRead().subscribe(() => {
      this.items.update((items) => items.map((item) => ({ ...item, is_read: true })));
    });
  }

  /**
   * Marks it read and goes to whatever it is about.
   *
   * The destination is derived here rather than sent by the API: a link is a
   * client-side route, and the API has no business knowing the Angular URL
   * structure.
   */
  protected openNotification(note: AppNotification): void {
    if (!note.is_read) {
      this.notifications.markRead(note.id).subscribe();
      this.items.update((items) =>
        items.map((item) => (item.id === note.id ? { ...item, is_read: true } : item)),
      );
    }

    const target = this.routeFor(note);
    this.close();

    if (target) {
      void this.router.navigateByUrl(target);
    }
  }

  private routeFor(note: AppNotification): string | null {
    const id = note.related_id;

    if (note.related_type === 'conversation' && id) {
      return `/messages/${id}`;
    }

    if (note.related_type === 'verification_document' || note.related_type === 'user') {
      return '/carrier/profile';
    }

    if (note.related_type !== 'freight_job' || !id) {
      return null;
    }

    // Reviews are role-agnostic, so both sides land on the same page.
    if (note.type === 'job.completed' || note.type === 'review.received') {
      return `/jobs/${id}/review`;
    }

    // Everything else about a load is role-specific, and sending a carrier to
    // a shipper-only route would just produce a 403.
    return this.auth.role() === 'shipper' ? `/shipper/jobs/${id}/quotes` : '/carrier/board';
  }

  /** "3 hours ago", without a date library for one label. */
  protected relative(iso: string | null): string {
    if (!iso) {
      return '';
    }

    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);

    if (seconds < 60) {
      return 'just now';
    }

    const format = new Intl.RelativeTimeFormat('en-AU', { numeric: 'auto' });

    // Largest unit that gives a value of at least one, so 90 minutes reads as
    // "1 hour ago" rather than "90 minutes ago".
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
      ['day', 86400],
      ['hour', 3600],
      ['minute', 60],
    ];

    for (const [unit, divisor] of steps) {
      const value = Math.floor(seconds / divisor);

      if (value >= 1) {
        // Past a week, an actual date is more use than "12 days ago".
        return unit === 'day' && value > 7
          ? new Date(iso).toLocaleDateString('en-AU')
          : format.format(-value, unit);
      }
    }

    return 'just now';
  }
}
