import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AuthService } from '../core/auth/auth.service';
import { Icon } from '../shared/icon';

const DISMISSED_KEY = 'freightmove.passwordNoticeDismissed';

/**
 * Invites customers whose accounts moved across from the previous site to
 * choose a new password.
 *
 * Deliberately a banner rather than a gate. These are existing paying customers
 * mid-task; blocking them on arrival would cost more than it protects, and
 * their current password still works. Dismissing hides it for the session only
 * — it returns on the next sign-in, so the nudge persists without nagging.
 */
@Component({
  selector: 'fm-password-migration-notice',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon],
  template: `
    @if (visible()) {
      <aside class="notice" role="status">
        <span class="glyph"><fm-icon name="shield-check" size="18" /></span>

        <div class="copy">
          <p class="title">Time for a fresh password</p>
          <p class="body">
            Your account came across from our previous site. Setting a new password brings it up to
            our current security standard — it takes a moment.
          </p>
        </div>

        <div class="actions">
          <a class="fm-btn fm-btn--sm" routerLink="/account/password">Set a new password</a>
          <button type="button" class="dismiss" (click)="dismiss()">Not now</button>
        </div>
      </aside>
    }
  `,
  styleUrl: './password-migration-notice.scss',
})
export class PasswordMigrationNotice {
  private readonly auth = inject(AuthService);

  /** Session-scoped so the reminder returns on the next sign-in. */
  private readonly dismissed = signal(sessionStorage.getItem(DISMISSED_KEY) === '1');

  protected readonly visible = () => this.auth.shouldUpdatePassword() && !this.dismissed();

  protected dismiss(): void {
    sessionStorage.setItem(DISMISSED_KEY, '1');
    this.dismissed.set(true);
  }
}
