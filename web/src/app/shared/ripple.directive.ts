import { DestroyRef, Directive, ElementRef, HostListener, inject } from '@angular/core';

/**
 * Material-style ripple that originates at the pointer.
 *
 * The span is appended on pointerdown and removes itself when its animation
 * ends, so nothing accumulates in the DOM. Skipped entirely under reduced
 * motion, and for keyboard activation — the focus ring is the affordance there.
 */
@Directive({
  selector: '[fmRipple]',
})
export class Ripple {
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly live = new Set<HTMLElement>();

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.live.forEach((node) => node.remove());
      this.live.clear();
    });
  }

  @HostListener('pointerdown', ['$event'])
  protected onPointerDown(event: PointerEvent): void {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    const host = this.host.nativeElement;
    const box = host.getBoundingClientRect();
    // Diameter large enough to cover the element from any origin.
    const size = Math.hypot(box.width, box.height) * 2;

    const ripple = document.createElement('span');
    ripple.className = 'fm-ripple';
    ripple.style.width = ripple.style.height = `${size}px`;
    ripple.style.left = `${event.clientX - box.left - size / 2}px`;
    ripple.style.top = `${event.clientY - box.top - size / 2}px`;

    ripple.addEventListener(
      'animationend',
      () => {
        ripple.remove();
        this.live.delete(ripple);
      },
      { once: true },
    );

    this.live.add(ripple);
    host.appendChild(ripple);
  }
}
