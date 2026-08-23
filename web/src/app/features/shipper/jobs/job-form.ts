import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { concatMap, from, of, tap } from 'rxjs';

import { AuthService } from '../../../core/auth/auth.service';
import { describeError, fieldErrors } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { PlaceField } from '../../../shared/place-field';
import { Ripple } from '../../../shared/ripple.directive';
import { LoadAvailability, LoadImage } from './job.models';
import { JobService } from './job.service';

/** What a chosen-but-not-yet-uploaded photo looks like while the form is open. */
interface PendingPhoto {
  file: File;
  /** An object URL for the thumbnail, revoked when the photo is dropped. */
  preview: string;
}

/** The controls that belong to each step, so a step can be validated alone. */
type StepControl =
  | 'title'
  | 'description'
  | 'category_ids'
  | 'quantity'
  | 'weight_kg'
  | 'length_mm'
  | 'width_mm'
  | 'height_mm'
  | 'pickup_location'
  | 'delivery_location'
  | 'availability'
  | 'pickup_date'
  | 'delivery_date'
  | 'truck_type_ids';

interface Step {
  label: string;
  /** Shown under the heading — what this step is actually asking for. */
  blurb: string;
  controls: StepControl[];
}

/**
 * Post a load.
 *
 * Split into five steps rather than one page: eighteen inputs in a single
 * column read as a chore and got abandoned, five short questions do not.
 *
 * The fields appear in the **legacy order**, because that is what returning
 * shippers have in their fingers. Only the step boundaries are new.
 *
 * Nothing is submitted until the last step, so the form is one create call as
 * before. Values persist across steps because the FormGroup outlives the
 * markup: Angular keeps a control's value when its directive is destroyed.
 *
 * Saving as a draft deliberately relaxes validation and is offered on every
 * step: half-finished loads are worth keeping, and a shipper should never lose
 * typed detail because they got interrupted at step two.
 */
@Component({
  selector: 'fm-job-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple, PlaceField],
  templateUrl: './job-form.html',
  styleUrl: './job-form.scss',
})
export class JobForm {
  /** The one vocabulary, from the API. */
  protected readonly taxonomy = toSignal(inject(JobService).taxonomy$, { initialValue: null });

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly details = signal<string[]>([]);

  /** Chosen photos, held until the load exists to attach them to. */
  protected readonly photos = signal<PendingPhoto[]>([]);

  private readonly jobs = inject(JobService);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);

  /**
   * The load being edited, or null when posting a new one.
   *
   * One component for both: the fields, their order and their rules are
   * identical, and a second copy would drift the first time one of them
   * changed.
   */
  protected readonly editingId = signal<number | null>(null);

  /** Photos already attached to the load, as the API returns them. */
  protected readonly saved = signal<LoadImage[]>([]);

  /** True while the existing load is being fetched. */
  protected readonly loadingJob = signal(false);

  protected readonly maxPhotos = 6;
  protected readonly photoAccept = 'image/jpeg,image/png,image/gif,image/webp,application/pdf';

  protected readonly canAddPhoto = computed(
    () => this.photos().length + this.saved().length < this.maxPhotos,
  );

  /**
   * The legacy field order, kept.
   *
   * `load_master`'s form asked in this sequence — description, lane,
   * availability, ready date, image, long description, category, truck type,
   * then the measurements and the contact block. Shippers who have used the
   * old site for years fill it in from muscle memory, and reordering it to
   * suit a tidier grouping costs them that for nothing.
   *
   * The split into steps is ours; the order inside them is not.
   */
  protected readonly steps: readonly Step[] = [
    {
      label: 'Load',
      blurb: 'What are you moving, and where to?',
      controls: [
        'title',
        'pickup_location',
        'delivery_location',
        'availability',
        'pickup_date',
        'delivery_date',
      ],
    },
    {
      label: 'Details',
      blurb: 'Photos and description',
      controls: ['description'],
    },
    {
      label: 'Type',
      blurb: 'Category and truck type',
      controls: ['category_ids', 'truck_type_ids'],
    },
    {
      label: 'Size',
      blurb: 'Quantity, dimensions and weight',
      controls: ['quantity', 'length_mm', 'width_mm', 'height_mm', 'weight_kg'],
    },
    {
      label: 'Contact',
      blurb: 'How carriers reach you',
      controls: [],
    },
  ];

  protected readonly step = signal(0);

  /** Which way the last move went, so the panel animates in from that side. */
  protected readonly direction = signal<'forward' | 'back'>('forward');

  protected readonly isLast = computed(() => this.step() === this.steps.length - 1);
  protected readonly current = computed(() => this.steps[this.step()]);

  /** Fill of the rail behind the step markers, as a percentage. */
  protected readonly progress = computed(() =>
    this.steps.length < 2 ? 100 : (this.step() / (this.steps.length - 1)) * 100,
  );

  protected readonly form = inject(FormBuilder).nonNullable.group({
    title: ['', [Validators.required, Validators.maxLength(255)]],
    pickup_location: ['', [Validators.required, Validators.maxLength(255)]],
    delivery_location: ['', [Validators.required, Validators.maxLength(255)]],
    pickup_date: [''],
    delivery_date: [''],
    availability: ['' as '' | LoadAvailability],
    // Multi-select: two-thirds of real loads suit more than one trailer.
    category_ids: [[] as number[]],
    truck_type_ids: [[] as number[]],
    // Free text, matching the legacy column: "3", "2 pallets", "1 x crate".
    quantity: ['', [Validators.maxLength(50)]],
    // Millimetres. 30,000 mm is 30 m, longer than any legal road combination.
    length_mm: [null as number | null, [Validators.min(1), Validators.max(30000)]],
    width_mm: [null as number | null, [Validators.min(1), Validators.max(30000)]],
    height_mm: [null as number | null, [Validators.min(1), Validators.max(30000)]],
    // Kilograms, as the shipper types it. The API derives tonnes for the board.
    weight_kg: [null as number | null, [Validators.min(1), Validators.max(100000)]],
    description: ['', [Validators.maxLength(5000)]],

    // Prefilled from the account. Saving the load writes any change back to the
    // profile, so these stay one set of details rather than a copy per load.
    contact: inject(FormBuilder).nonNullable.group({
      first_name: ['', [Validators.maxLength(100)]],
      last_name: ['', [Validators.maxLength(100)]],
      email: ['', [Validators.email, Validators.maxLength(255)]],
      phone: ['', [Validators.maxLength(32)]],
    }),
  });

  constructor() {
    const user = this.auth.user();

    if (user) {
      this.form.controls.contact.patchValue({
        first_name: user.first_name ?? '',
        last_name: user.last_name ?? '',
        email: user.email ?? '',
        phone: user.phone ?? '',
      });
    }

    const id = Number(this.route.snapshot.paramMap.get('id'));

    if (id) {
      this.editingId.set(id);
      this.fetch(id);
    }
  }

  /** Loads the existing job and fills the form with it. */
  private fetch(id: number): void {
    this.loadingJob.set(true);

    this.jobs.get(id).subscribe({
      next: (job) => {
        this.loadingJob.set(false);
        this.saved.set(job.images ?? []);

        this.form.patchValue({
          title: job.title ?? '',
          pickup_location: job.pickup_location ?? '',
          delivery_location: job.delivery_location ?? '',
          // The API sends dates as YYYY-MM-DD, which is what a date input wants.
          pickup_date: job.pickup_date ?? '',
          delivery_date: job.delivery_date ?? '',
          availability: job.availability ?? '',
          quantity: job.quantity ?? '',
          length_mm: job.length_mm,
          width_mm: job.width_mm,
          height_mm: job.height_mm,
          weight_kg: job.weight_kg,
          description: job.description ?? '',
          // Taken from the loaded relations, not the denormalised singular
          // columns: those hold only the first value.
          category_ids: (job.categories ?? []).map((c) => c.id),
          truck_type_ids: (job.truck_types ?? []).map((t) => t.id),
        });
      },
      error: (response: HttpErrorResponse) => {
        this.loadingJob.set(false);
        this.error.set(describeError(response, 'Could not open that load.'));
      },
    });
  }

  /** Removes a photo that is already stored on the load. */
  protected removeSaved(image: LoadImage): void {
    const id = this.editingId();

    if (!id) {
      return;
    }

    this.jobs.removeImage(id, image.path).subscribe({
      next: (response) => this.saved.set(response.data.images),
      error: (response: HttpErrorResponse) =>
        this.error.set(describeError(response, 'Could not remove that photo.')),
    });
  }

  // -- Stepping -------------------------------------------------------------

  /** True once every control on a step is valid — drives the tick on the rail. */
  protected stepDone(index: number): boolean {
    return index < this.step() && this.stepValid(index);
  }

  private stepValid(index: number): boolean {
    const step = this.steps[index];
    const ok = step.controls.every((name) => this.form.controls[name].valid);

    // Contact lives on the last step alongside the preference fields.
    return index === this.steps.length - 1 ? ok && this.form.controls.contact.valid : ok;
  }

  protected next(): void {
    if (!this.stepValid(this.step())) {
      this.touch(this.step());
      this.error.set('Check the highlighted fields before continuing.');
      return;
    }

    this.error.set(null);
    this.direction.set('forward');
    this.step.update((value) => Math.min(value + 1, this.steps.length - 1));
    this.scrollToTop();
  }

  protected back(): void {
    this.error.set(null);
    this.direction.set('back');
    this.step.update((value) => Math.max(value - 1, 0));
    this.scrollToTop();
  }

  /**
   * Jumping via the rail. Backwards is always allowed; forwards only into a
   * step already cleared, so the rail cannot be used to skip validation.
   */
  protected goTo(index: number): void {
    if (index === this.step()) {
      return;
    }

    if (index > this.step() && !this.stepValid(this.step())) {
      this.touch(this.step());
      this.error.set('Check the highlighted fields before continuing.');
      return;
    }

    this.error.set(null);
    this.direction.set(index > this.step() ? 'forward' : 'back');
    this.step.set(index);
    this.scrollToTop();
  }

  /** Marks a step's controls touched so their errors become visible. */
  private touch(index: number): void {
    for (const name of this.steps[index].controls) {
      this.form.controls[name].markAsTouched();
    }

    if (index === this.steps.length - 1) {
      this.form.controls.contact.markAllAsTouched();
    }
  }

  private scrollToTop(): void {
    // The panel is taller than the viewport on a phone; without this, moving to
    // step two lands halfway down the next question.
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // -- Photos ---------------------------------------------------------------

  protected onPhotosChosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    const chosen = Array.from(input.files ?? []);

    const room = this.maxPhotos - this.photos().length;
    const accepted = chosen.slice(0, room).map((file) => ({
      file,
      preview: URL.createObjectURL(file),
    }));

    if (chosen.length > room) {
      this.error.set(`You can attach up to ${this.maxPhotos} photos.`);
    }

    this.photos.update((current) => [...current, ...accepted]);

    // Clear the input so choosing the same file twice still fires a change.
    input.value = '';
  }

  protected removePhoto(index: number): void {
    this.photos.update((current) => {
      // Object URLs are held by the browser until revoked; dropping the
      // reference alone leaks the blob for the life of the page.
      URL.revokeObjectURL(current[index].preview);
      return current.filter((_, i) => i !== index);
    });
  }

  // -- Submit ---------------------------------------------------------------

  /** Publishing runs full validation; a draft only needs a title. */
  protected submit(status: 'draft' | 'published'): void {
    if (status === 'published' && this.form.invalid) {
      this.form.markAllAsTouched();

      // Send them to the first step that is actually wrong, rather than
      // reporting an error about a field three steps back that they cannot see.
      const broken = this.steps.findIndex((_, index) => !this.stepValid(index));
      if (broken >= 0 && broken !== this.step()) {
        this.direction.set(broken > this.step() ? 'forward' : 'back');
        this.step.set(broken);
        this.scrollToTop();
      }

      this.error.set('Check the highlighted fields before publishing.');
      return;
    }

    if (status === 'draft' && !this.editingId() && this.form.controls.title.invalid) {
      this.form.controls.title.markAsTouched();
      this.direction.set('back');
      this.step.set(0);
      this.error.set('Give the load a title so you can find the draft again.');
      return;
    }

    this.busy.set(true);
    this.error.set(null);
    this.details.set([]);

    const raw = this.form.getRawValue();
    const photos = this.photos();
    const editing = this.editingId();

    const draft = {
      ...raw,
      // Empty strings would fail the API's date and numeric rules.
      pickup_date: raw.pickup_date || null,
      delivery_date: raw.delivery_date || null,
      availability: raw.availability || null,
      description: raw.description || null,
      quantity: raw.quantity || null,
      contact: {
        first_name: raw.contact.first_name || null,
        last_name: raw.contact.last_name || null,
        email: raw.contact.email || null,
        phone: raw.contact.phone || null,
      },
    };

    // Editing never changes the status. A live load stays live and a draft
    // stays a draft; publishing is its own action on the list, so saving an
    // edit cannot put a half-finished load in front of carriers by accident.
    const save = editing
      ? this.jobs.update(editing, draft)
      : this.jobs.create({ ...draft, status });

    save
      .pipe(
        // The load has to exist before a photo can hang off it, so the uploads
        // run after the create and one at a time — a shipper on a phone
        // attaching six photos should not open six parallel connections.
        concatMap((response) => {
          const jobId = response.data.id;

          return photos.length === 0
            ? of(response)
            : from(photos).pipe(
                concatMap((photo) => this.jobs.addImage(jobId, photo.file)),
                tap({ complete: () => photos.forEach((p) => URL.revokeObjectURL(p.preview)) }),
              );
        }),
      )
      .subscribe({
        next: () => undefined,
        complete: () => void this.router.navigateByUrl('/shipper/jobs'),
        error: (response: HttpErrorResponse) => {
          this.busy.set(false);
          this.error.set(describeError(response, 'Could not save that load.'));
          this.details.set(fieldErrors(response));
        },
      });
  }

  // -- Template helpers -----------------------------------------------------

  /** Toggles a value in one of the two multi-select controls. */
  protected toggle(control: 'category_ids' | 'truck_type_ids', id: number): void {
    const field = this.form.controls[control];
    const current = field.value;

    field.setValue(
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    );
  }

  protected isSelected(control: 'category_ids' | 'truck_type_ids', id: number): boolean {
    return this.form.controls[control].value.includes(id);
  }

  protected invalid(control: keyof typeof this.form.controls): boolean {
    const field = this.form.controls[control];
    return field.invalid && field.touched;
  }

  protected contactInvalid(control: 'first_name' | 'last_name' | 'email' | 'phone'): boolean {
    const field = this.form.controls.contact.controls[control];
    return field.invalid && field.touched;
  }

  /** A one-line recap of the load, shown on the final step. */
  protected readonly summary = computed(() => {
    const value = this.form.getRawValue();
    const lane =
      value.pickup_location && value.delivery_location
        ? `${value.pickup_location} → ${value.delivery_location}`
        : null;

    const size = [
      value.quantity || null,
      value.weight_kg ? `${value.weight_kg.toLocaleString()} kg` : null,
      value.length_mm || value.width_mm || value.height_mm
        ? [value.length_mm, value.width_mm, value.height_mm]
            .filter(Boolean)
            .map((mm) => (mm as number).toLocaleString())
            .join(' × ') + ' mm'
        : null,
    ].filter(Boolean) as string[];

    return {
      title: value.title || null,
      lane,
      size: size.length > 0 ? size.join(' · ') : null,
      photos: this.photos().length,
    };
  });
}
