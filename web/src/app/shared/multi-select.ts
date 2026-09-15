import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  HostListener,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';

import { Icon } from './icon';

export interface MultiSelectOption {
  id: number;
  name: string;
}

/**
 * A compact multi-select, for taxonomies that would otherwise be a wall of
 * chips.
 *
 * Category and truck type each list a dozen options. Laid out as chip rows
 * they took more vertical space than the rest of the form put together, which
 * on a phone meant scrolling past two screens of buttons to reach the fields
 * underneath.
 *
 * **Still multi-select.** A native `<select>` would be smaller still and would
 * also be wrong: two-thirds of real loads suit more than one trailer, and the
 * board's filters are built on that. So this stays a set, and the trigger
 * reports how much of it is chosen.
 *
 * Renders the ordinary `.fm-field` shell so it sits in the form as a field
 * rather than as a differently-styled widget dropped into the middle of one.
 */
@Component({
  selector: 'fm-multi-select',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [Icon],
  template: `
    <div class="wrap">
      <button
        type="button"
        class="trigger"
        [class.is-open]="open()"
        [attr.aria-expanded]="open()"
        aria-haspopup="listbox"
        [attr.aria-label]="label() + ': ' + summary()"
        (click)="toggleOpen()"
      >
        <span class="value" [class.is-empty]="selected().length === 0">{{ summary() }}</span>
        <fm-icon class="caret" name="chevron-down" size="16" [strokeWidth]="2.2" />
      </button>

      @if (open()) {
        <div class="panel" role="listbox" [attr.aria-multiselectable]="true">
          @for (option of options(); track option.id) {
            <button
              type="button"
              class="option"
              role="option"
              [class.on]="isOn(option.id)"
              [attr.aria-selected]="isOn(option.id)"
              (click)="pick(option.id)"
            >
              <span class="box">
                @if (isOn(option.id)) {
                  <fm-icon name="check" size="12" [strokeWidth]="3" />
                }
              </span>
              {{ option.name }}
            </button>
          } @empty {
            <p class="none">Nothing to choose from yet.</p>
          }
        </div>
      }
    </div>
  `,
  styles: `
    :host {
      display: block;
    }

    .wrap {
      position: relative;
    }

    .trigger {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      width: 100%;
      padding: 0.85rem 0.95rem;
      border: 0;
      border-radius: var(--fm-radius);
      background: var(--fm-paper);
      box-shadow: inset 0 0 0 1px var(--fm-field-line);
      color: var(--fm-ink);
      font: inherit;
      font-weight: 600;
      text-align: left;
      cursor: pointer;
      transition: box-shadow 0.15s ease;
    }

    .trigger:hover {
      box-shadow: inset 0 0 0 1px var(--fm-field-line-hover);
    }

    .trigger.is-open,
    .trigger:focus-visible {
      outline: none;
      box-shadow: inset 0 0 0 2px var(--fm-blue-bright, #2f6fd0);
    }

    .value {
      flex: 1;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    /* "Any" is a real answer here, not a missing one, so it reads as placeholder
       text rather than as an unfilled required field. */
    .value.is-empty {
      color: var(--fm-ink-faint);
      font-weight: 500;
    }

    .caret {
      flex: none;
      color: var(--fm-ink-faint);
      transition: transform 0.15s ease;
    }

    .trigger.is-open .caret {
      transform: rotate(180deg);
    }

    .panel {
      position: absolute;
      z-index: 20;
      top: calc(100% + 0.35rem);
      left: 0;
      right: 0;
      max-height: 17rem;
      overflow-y: auto;
      padding: 0.35rem;
      border-radius: var(--fm-radius);
      background: var(--fm-surface, #ffffff);
      box-shadow:
        0 0 0 1px var(--fm-line),
        var(--fm-shadow-lg, 0 18px 40px -18px rgb(10 28 56 / 35%));
    }

    .option {
      display: flex;
      align-items: center;
      gap: 0.6rem;
      width: 100%;
      padding: 0.55rem 0.6rem;
      border: 0;
      border-radius: calc(var(--fm-radius) - 0.2rem);
      background: none;
      color: var(--fm-ink);
      font: inherit;
      text-align: left;
      cursor: pointer;
    }

    .option:hover,
    .option:focus-visible {
      outline: none;
      background: var(--fm-paper);
    }

    .box {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: none;
      width: 1.05rem;
      height: 1.05rem;
      border-radius: 0.3rem;
      background: var(--fm-paper);
      box-shadow: inset 0 0 0 1px var(--fm-field-line);
      color: #ffffff;
    }

    .option.on .box {
      background: var(--fm-blue-bright, #2f6fd0);
      box-shadow: none;
    }

    .option.on {
      font-weight: 600;
    }

    .none {
      margin: 0;
      padding: 0.6rem;
      color: var(--fm-ink-faint);
    }

    @media (prefers-reduced-motion: reduce) {
      .trigger,
      .caret {
        transition: none;
      }
    }
  `,
})
export class MultiSelect {
  readonly label = input.required<string>();
  readonly options = input<MultiSelectOption[]>([]);
  readonly selected = input<number[]>([]);

  /** What the trigger says when nothing is chosen. */
  readonly placeholder = input('Any');

  /** One id, toggled. The form owns the array; this only reports the intent. */
  readonly picked = output<number>();

  protected readonly open = signal(false);

  private readonly host: ElementRef<HTMLElement> = inject(ElementRef);

  /**
   * Names while they fit, a count once they do not.
   *
   * Listing every choice is more useful than "4 selected" right up until it
   * stops fitting on one line, at which point a truncated list of names is
   * worse than a number.
   */
  protected readonly summary = computed(() => {
    const chosen = this.options().filter((option) => this.selected().includes(option.id));

    if (chosen.length === 0) {
      return this.placeholder();
    }

    return chosen.length <= 2
      ? chosen.map((option) => option.name).join(', ')
      : `${chosen.length} selected`;
  });

  protected isOn(id: number): boolean {
    return this.selected().includes(id);
  }

  protected toggleOpen(): void {
    this.open.update((value) => !value);
  }

  /** Stays open: choosing more than one is the normal case, not the exception. */
  protected pick(id: number): void {
    this.picked.emit(id);
  }

  @HostListener('document:click', ['$event'])
  protected onDocumentClick(event: MouseEvent): void {
    if (this.open() && !this.host.nativeElement.contains(event.target as Node)) {
      this.open.set(false);
    }
  }

  @HostListener('keydown.escape')
  protected onEscape(): void {
    this.open.set(false);
  }
}
