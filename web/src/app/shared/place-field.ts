import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  forwardRef,
  inject,
  input,
  signal,
} from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { GooglePlacesService, PlaceSuggestion } from '../core/places/google-places.service';
import { Icon } from './icon';

/**
 * A text field with Google Places autocomplete behind it.
 *
 * Renders the ordinary `.fm-field` markup rather than Google's own widget, so
 * it inherits every rule the rest of the form uses — the pinned label, the
 * focus colour, the hint and error treatments — instead of arriving as a
 * differently-styled box in the middle of a designed form.
 *
 * **The value is the text.** Nothing here stores a place id or coordinates:
 * `pickup_location` and `delivery_location` are strings and stay strings, so a
 * shipper who ignores the dropdown and types "Brisbane" by hand produces
 * exactly what the picker would have produced. The autocomplete is an
 * accelerator, never a gate.
 *
 * The hint and error live inside this component rather than beside it because
 * `.fm-field:focus-within > .hint` needs them as siblings of the control.
 */
@Component({
  selector: 'fm-place-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => PlaceField),
      multi: true,
    },
  ],
  template: `
    <div class="fm-field fm-field--with-icon">
      <div class="fm-field__control" [class.is-invalid]="invalid()">
        <fm-icon name="map-pin" size="16" />
        <input
          [id]="inputId()"
          class="fm-field__input"
          type="text"
          autocomplete="off"
          placeholder=" "
          [value]="value()"
          [disabled]="disabled()"
          [attr.aria-invalid]="invalid()"
          [attr.aria-expanded]="open()"
          [attr.aria-controls]="inputId() + '-list'"
          role="combobox"
          aria-autocomplete="list"
          (input)="onType($any($event.target).value)"
          (blur)="onBlur()"
          (keydown)="onKey($event)"
        />
        <label class="fm-field__label" [attr.for]="inputId()">{{ label() }}</label>
      </div>

      @if (open()) {
        <ul class="places" [id]="inputId() + '-list'" role="listbox">
          @for (place of suggestions(); track place.text; let i = $index) {
            <li
              role="option"
              [attr.aria-selected]="i === active()"
              [class.on]="i === active()"
              (mousedown)="choose(place, $event)"
              (mouseenter)="active.set(i)"
            >
              <fm-icon name="map-pin" size="13" />
              <span class="places-text">
                <span class="places-main">{{ place.main }}</span>
                @if (place.secondary) {
                  <span class="places-sub">{{ place.secondary }}</span>
                }
              </span>
            </li>
          }
        </ul>
      }

      @if (invalid() && errorText()) {
        <p class="field-error">{{ errorText() }}</p>
      } @else if (hint()) {
        <p class="hint">{{ hint() }}</p>
      }
    </div>
  `,
  styleUrl: './place-field.scss',
})
export class PlaceField implements ControlValueAccessor {
  readonly inputId = input.required<string>();
  readonly label = input.required<string>();
  readonly hint = input<string>('');
  readonly errorText = input<string>('');
  readonly invalid = input<boolean>(false);

  protected readonly value = signal('');
  protected readonly disabled = signal(false);
  protected readonly suggestions = signal<PlaceSuggestion[]>([]);
  protected readonly active = signal(-1);

  private readonly places = inject(GooglePlacesService);
  private readonly host = inject(ElementRef<HTMLElement>);

  /** Pending debounce, so a fast typist costs one request rather than eight. */
  private timer: ReturnType<typeof setTimeout> | null = null;

  /** Guards against an old response landing after a newer one. */
  private sequence = 0;

  protected open(): boolean {
    return this.suggestions().length > 0;
  }

  // -- ControlValueAccessor -------------------------------------------------

  private onChange: (value: string) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  writeValue(value: string | null): void {
    this.value.set(value ?? '');
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled.set(isDisabled);
  }

  // -- Typing ---------------------------------------------------------------

  protected onType(text: string): void {
    // The control updates on every keystroke regardless of what Google says.
    // Someone typing an address that has no prediction must still end up with
    // their address in the form.
    this.value.set(text);
    this.onChange(text);
    this.active.set(-1);

    if (this.timer) {
      clearTimeout(this.timer);
    }

    if (!this.places.configured || text.trim().length < 2) {
      this.suggestions.set([]);
      return;
    }

    const run = ++this.sequence;

    this.timer = setTimeout(async () => {
      const results = await this.places.suggest(text);

      // A slower earlier request must not overwrite a newer answer.
      if (run === this.sequence) {
        this.suggestions.set(results);
      }
    }, 220);
  }

  protected onKey(event: KeyboardEvent): void {
    if (!this.open()) {
      return;
    }

    const last = this.suggestions().length - 1;

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.active.update((i) => (i >= last ? 0 : i + 1));
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.active.update((i) => (i <= 0 ? last : i - 1));
        break;
      case 'Enter': {
        const chosen = this.suggestions()[this.active()];
        if (chosen) {
          // Only swallow the key when it is actually selecting something,
          // so Enter still submits a form from an untouched field.
          event.preventDefault();
          this.choose(chosen);
        }
        break;
      }
      case 'Escape':
        this.dismiss();
        break;
    }
  }

  /**
   * `mousedown` rather than `click`: blur fires first on a click and would
   * close the list before the selection landed.
   */
  protected choose(place: PlaceSuggestion, event?: Event): void {
    event?.preventDefault();

    this.value.set(place.text);
    this.onChange(place.text);
    this.dismiss();

    // The address is settled, so the billing session ends here.
    this.places.endSession();

    this.host.nativeElement.querySelector('input')?.focus();
  }

  protected onBlur(): void {
    this.onTouched();
    // Deferred so a mousedown on the list is not cut off by the blur.
    setTimeout(() => this.dismiss(), 120);
  }

  private dismiss(): void {
    this.suggestions.set([]);
    this.active.set(-1);
  }
}
