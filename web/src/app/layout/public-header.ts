import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  HostListener,
  afterNextRender,
  effect,
  inject,
  signal,
} from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { filter, map } from 'rxjs';

import { AuthService } from '../core/auth/auth.service';
import { Icon } from '../shared/icon';
import { SectionSpy } from '../shared/section-spy';
import { Wordmark } from '../shared/wordmark';
import { CONTACT_PHONE, CONTACT_PHONE_HREF, NavGroup, PUBLIC_NAV } from './public-nav';

@Component({
  selector: 'fm-public-header',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, RouterLinkActive, Icon, Wordmark],
  templateUrl: './public-header.html',
  styleUrl: './public-header.scss',
})
export class PublicHeader {
  protected readonly auth = inject(AuthService);
  protected readonly nav = PUBLIC_NAV;
  protected readonly phone = CONTACT_PHONE;
  protected readonly phoneHref = CONTACT_PHONE_HREF;

  /**
   * How long the dropdown survives after the cursor leaves it.
   *
   * Long enough to cross the gap between trigger and panel, short enough that
   * leaving on purpose still feels instant.
   */
  private static readonly CLOSE_DELAY_MS = 160;

  private closeTimer: ReturnType<typeof setTimeout> | null = null;

  /** Label of the open desktop dropdown, or null when all are closed. */
  protected readonly openGroup = signal<string | null>(null);
  protected readonly drawerOpen = signal(false);
  /** Label of the expanded accordion group inside the mobile drawer. */
  protected readonly openDrawerGroup = signal<string | null>(null);

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly router = inject(Router);
  private readonly spy = inject(SectionSpy);

  /** Section currently in view, used for the active nav indicator. */
  protected readonly activeSection = this.spy.active;

  /** Drops a shadow onto the bar once the page scrolls away from the top. */
  protected readonly scrolled = signal(false);
  /** 0–100, drives the reading-progress rule under the bar. */
  protected readonly progress = signal(0);

  private readonly navigated = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
  );

  constructor() {
    // Any navigation closes whatever the user had open.
    effect(() => {
      this.navigated();
      this.closeAll();
    });

    // A pending close must not outlive the component and write to a destroyed
    // signal.
    inject(DestroyRef).onDestroy(() => this.cancelPendingClose());

    // Watch the home-page sections so the nav can mark the one in view. The
    // ids only exist on the home page; elsewhere the spy simply finds nothing.
    afterNextRender(() => {
      this.spy.observe(this.nav.map((group) => group.section));
      this.onScroll();
    });

    // The drawer is a full-screen overlay, so the page behind it must not scroll.
    effect(() => {
      document.body.style.overflow = this.drawerOpen() ? 'hidden' : '';
    });
  }

  @HostListener('window:scroll')
  protected onScroll(): void {
    this.scrolled.set(window.scrollY > 8);

    const scrollable = document.documentElement.scrollHeight - window.innerHeight;
    this.progress.set(scrollable > 0 ? Math.min((window.scrollY / scrollable) * 100, 100) : 0);
  }

  @HostListener('document:click', ['$event'])
  protected onDocumentClick(event: MouseEvent): void {
    if (!this.host.nativeElement.contains(event.target as Node)) {
      this.openGroup.set(null);
    }
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    this.closeAll();
  }

  protected toggleGroup(group: NavGroup): void {
    this.openGroup.update((current) => (current === group.label ? null : group.label));
  }

  protected openOnHover(group: NavGroup): void {
    // Only take over on pointer devices; touch taps go through toggleGroup.
    if (! window.matchMedia('(hover: hover)').matches) {
      return;
    }

    // Cancels a close already scheduled — either the cursor came back, or it
    // moved straight to a neighbouring menu, which should switch instantly.
    this.cancelPendingClose();
    this.openGroup.set(group.label);
  }

  /**
   * Closes after a short grace period rather than immediately.
   *
   * Moving from the trigger down to an item is rarely a straight line: the
   * cursor clips the corner of the panel, or crosses the seam between the two.
   * Closing on the first `mouseleave` made the menu unusable — the panel
   * vanished before the pointer reached it. The delay is short enough to feel
   * immediate when you genuinely leave.
   */
  protected closeOnLeave(): void {
    if (! window.matchMedia('(hover: hover)').matches) {
      return;
    }

    this.cancelPendingClose();
    this.closeTimer = setTimeout(() => {
      this.openGroup.set(null);
      this.closeTimer = null;
    }, PublicHeader.CLOSE_DELAY_MS);
  }

  private cancelPendingClose(): void {
    if (this.closeTimer !== null) {
      clearTimeout(this.closeTimer);
      this.closeTimer = null;
    }
  }

  protected toggleDrawer(): void {
    this.drawerOpen.update((open) => !open);
  }

  protected toggleDrawerGroup(group: NavGroup): void {
    this.openDrawerGroup.update((current) => (current === group.label ? null : group.label));
  }

  protected signOut(): void {
    this.closeAll();
    this.auth.logout();
  }

  private closeAll(): void {
    this.cancelPendingClose();
    this.openGroup.set(null);
    this.drawerOpen.set(false);
    this.openDrawerGroup.set(null);
  }
}
