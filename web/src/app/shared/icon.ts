import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';

import { FM_ICONS, IconName } from './icons';

/**
 * Renders an icon from the inline set in `icons.ts`.
 *
 * The icon sizes itself from the current font-size (1em) unless `size` is set,
 * and inherits `currentColor`, so callers style it like text.
 *
 * `bypassSecurityTrustHtml` is safe here: the only values ever passed are the
 * compile-time constants in FM_ICONS, never user input. Angular's HTML
 * sanitizer would otherwise strip presentation attributes off the paths.
 */
@Component({
  selector: 'fm-icon',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <svg
      [attr.width]="size()"
      [attr.height]="size()"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      [attr.stroke-width]="strokeWidth()"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
      focusable="false"
      [innerHTML]="markup()"
    ></svg>
  `,
  styles: `
    :host {
      display: inline-flex;
      line-height: 0;
    }
  `,
})
export class Icon {
  readonly name = input.required<IconName>();
  readonly size = input<string>('1em');
  readonly strokeWidth = input<number>(1.6);

  private readonly sanitizer = inject(DomSanitizer);

  /**
   * The registry lookup is the security boundary, not the `IconName` type: a
   * type cannot stop a value arriving from an API response at runtime. Only
   * keys actually present in FM_ICONS are ever trusted, so an unknown name
   * renders nothing rather than reaching the sanitiser bypass.
   */
  protected readonly markup = computed<SafeHtml>(() => {
    const name = this.name();
    const svg = Object.prototype.hasOwnProperty.call(FM_ICONS, name)
      ? FM_ICONS[name]
      : '';

    return this.sanitizer.bypassSecurityTrustHtml(svg);
  });
}
