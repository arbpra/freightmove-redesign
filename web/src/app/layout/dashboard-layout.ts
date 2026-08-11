import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';

import { AuthService } from '../core/auth/auth.service';
import { NotificationService } from '../core/notifications/notification.service';
import { Icon } from '../shared/icon';
import { DASHBOARD_NAV, DashboardLink } from './dashboard-nav';
import { NotificationBell } from './notification-bell';
import { PasswordMigrationNotice } from './password-migration-notice';
import { Wordmark } from '../shared/wordmark';

/**
 * Chrome for the authenticated areas: a navigation rail on desktop, a tab bar
 * on mobile (docs/03-ui-ux-plan.md section 4.2).
 *
 * There is deliberately no search box and no notification bell. Both appear in
 * the wireframe, and neither has an endpoint behind it yet — chrome that looks
 * functional and does nothing costs more trust than the empty space costs
 * polish. They go in with the notifications work in Phase 4.
 */
@Component({
  selector: 'fm-dashboard-layout',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    PasswordMigrationNotice,
    NotificationBell,
    Icon,
    Wordmark,
  ],
  templateUrl: './dashboard-layout.html',
  styleUrl: './dashboard-layout.scss',
})
export class DashboardLayout {
  protected readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  protected readonly menuOpen = signal(false);

  constructor() {
    // Starts the badge polling. Done here rather than in the service's own
    // constructor because the service is app-wide and the public pages have no
    // session to poll with.
    inject(NotificationService).start();
  }

  protected readonly links = computed<DashboardLink[]>(
    () => DASHBOARD_NAV[this.auth.role() ?? ''] ?? [],
  );

  /** First letter of the signed-in user's name, for the avatar disc. */
  protected readonly initial = computed(() =>
    (this.auth.user()?.name ?? '?').trim().charAt(0).toUpperCase(),
  );

  /**
   * The current page's label, shown beside the wordmark on mobile where the
   * rail is not visible to say where you are.
   */
  private readonly url = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );

  protected readonly pageLabel = computed(() => {
    const url = this.url().split('?')[0];

    // Longest match wins, so /shipper/jobs beats /shipper.
    const match = [...this.links()]
      .filter((link) => url === link.path || url.startsWith(`${link.path}/`))
      .sort((a, b) => b.path.length - a.path.length)[0];

    return match?.label ?? '';
  });

  protected toggleMenu(): void {
    this.menuOpen.update((open) => !open);
  }

  protected closeMenu(): void {
    this.menuOpen.set(false);
  }

  protected signOut(): void {
    this.auth.logout();
  }
}
