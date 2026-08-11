import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';
import { Icon } from '../../../shared/icon';
import { AdminOverview as Overview, AdminService } from '../admin.service';

/**
 * The admin console's front page.
 *
 * Organised around the three questions an operator of a two-sided marketplace
 * actually has: is anything waiting on us, are the two sides meeting, and how
 * is the cut-over from the old platform going.
 */
@Component({
  selector: 'fm-admin-overview',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  templateUrl: './overview.html',
  styleUrl: './overview.scss',
})
export class AdminOverview {
  protected readonly auth = inject(AuthService);

  protected readonly data = signal<Overview | null>(null);
  protected readonly loading = signal(true);

  private readonly admin = inject(AdminService);

  constructor() {
    this.admin.overview().subscribe({
      next: (overview) => {
        this.data.set(overview);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  /** Share of migrated carriers who could still quote if a gate were switched on. */
  protected share(part: number, whole: number): number {
    return whole > 0 ? Math.round((part / whole) * 100) : 0;
  }
}
