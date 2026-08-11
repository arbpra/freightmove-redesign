import { DestroyRef, Injectable, inject, signal } from '@angular/core';

/**
 * Tracks which page section is currently in view so the navigation can show an
 * active indicator.
 *
 * A single IntersectionObserver watches every registered section and reports
 * the one nearest the top of the viewport. The band across the middle of the
 * screen is what counts as "in view" — using the whole viewport would make two
 * sections active at once on tall screens.
 */
@Injectable({ providedIn: 'root' })
export class SectionSpy {
  /** Id of the section currently in view, or null above the first one. */
  readonly active = signal<string | null>(null);

  private observer?: IntersectionObserver;
  private readonly visible = new Map<string, number>();

  constructor() {
    inject(DestroyRef).onDestroy(() => this.stop());
  }

  /** Begins watching the given section ids. Safe to call repeatedly. */
  observe(ids: string[]): void {
    this.stop();

    if (typeof IntersectionObserver === 'undefined') {
      return;
    }

    this.observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            this.visible.set(entry.target.id, entry.boundingClientRect.top);
          } else {
            this.visible.delete(entry.target.id);
          }
        }

        // Nearest the top wins, so scrolling down hands off cleanly.
        const nearest = [...this.visible.entries()].sort((a, b) => a[1] - b[1])[0];
        this.active.set(nearest?.[0] ?? null);
      },
      { rootMargin: '-45% 0px -45% 0px' },
    );

    for (const id of ids) {
      const element = document.getElementById(id);
      if (element) {
        this.observer.observe(element);
      }
    }
  }

  stop(): void {
    this.observer?.disconnect();
    this.observer = undefined;
    this.visible.clear();
    this.active.set(null);
  }
}
