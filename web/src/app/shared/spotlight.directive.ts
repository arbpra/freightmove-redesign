import { Directive, ElementRef, HostListener, inject } from '@angular/core';

/**
 * Publishes the pointer's position within the element as `--mx` / `--my`
 * percentages, so a card can paint a highlight that follows the cursor.
 *
 * The directive only writes the custom properties; each surface decides what to
 * do with them (see the `.spotlight` rules in the card sections). Pointer moves
 * are throttled to one write per frame.
 */
@Directive({
  selector: '[fmSpotlight]',
})
export class Spotlight {
  private readonly host = inject(ElementRef<HTMLElement>);
  private frame = 0;

  @HostListener('pointermove', ['$event'])
  protected onMove(event: PointerEvent): void {
    if (this.frame) {
      return;
    }

    this.frame = requestAnimationFrame(() => {
      this.frame = 0;
      const element = this.host.nativeElement;
      const box = element.getBoundingClientRect();
      element.style.setProperty('--mx', `${((event.clientX - box.left) / box.width) * 100}%`);
      element.style.setProperty('--my', `${((event.clientY - box.top) / box.height) * 100}%`);
    });
  }
}
