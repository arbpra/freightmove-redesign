import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { toSignal } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';

import { describeError, fieldErrors } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import { LoadAvailability } from './job.models';
import { JobService } from './job.service';

/**
 * Post a load.
 *
 * Saving as a draft deliberately relaxes validation: half-finished loads are
 * worth keeping, and a shipper should never lose typed detail because a date is
 * missing. Publishing runs the full rule set.
 */
@Component({
  selector: 'fm-job-form',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Ripple],
  templateUrl: './job-form.html',
  styleUrl: './job-form.scss',
})
export class JobForm {


  /** The one vocabulary, from the API. */
  protected readonly taxonomy = toSignal(inject(JobService).taxonomy$, { initialValue: null });

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly details = signal<string[]>([]);

  private readonly jobs = inject(JobService);
  private readonly router = inject(Router);

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
    weight_tons: [null as number | null, [Validators.min(0), Validators.max(200)]],
    budget_min: [null as number | null, [Validators.min(0)]],
    budget_max: [null as number | null, [Validators.min(0)]],
    description: ['', [Validators.maxLength(5000)]],
  });

  /** Publishing runs full validation; a draft only needs a title. */
  protected submit(status: 'draft' | 'published'): void {
    if (status === 'published' && this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set('Check the highlighted fields before publishing.');
      return;
    }

    if (status === 'draft' && this.form.controls.title.invalid) {
      this.form.controls.title.markAsTouched();
      this.error.set('Give the load a title so you can find the draft again.');
      return;
    }

    this.busy.set(true);
    this.error.set(null);
    this.details.set([]);

    const raw = this.form.getRawValue();

    this.jobs
      .create({
        ...raw,
        // Empty strings would fail the API's date and numeric rules.
        pickup_date: raw.pickup_date || null,
        delivery_date: raw.delivery_date || null,
        availability: raw.availability || null,
        description: raw.description || null,
        status,
      })
      .subscribe({
        next: () => void this.router.navigateByUrl('/shipper/jobs'),
        error: (response: HttpErrorResponse) => {
          this.busy.set(false);
          this.error.set(describeError(response, 'Could not save that load.'));
          this.details.set(fieldErrors(response));
        },
      });
  }

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
}
