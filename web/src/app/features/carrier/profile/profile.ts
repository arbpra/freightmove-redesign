import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';

import { describeError } from '../../../core/http/describe-error';
import { Icon } from '../../../shared/icon';
import { Ripple } from '../../../shared/ripple.directive';
import {
  DOCUMENT_STATUS_LABEL,
  VERIFICATION_LABEL,
  CarrierProfilePayload,
  VerificationDocument,
} from './profile.models';
import { CarrierProfileService } from './profile.service';

@Component({
  selector: 'fm-carrier-profile',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, Icon, Ripple],
  templateUrl: './profile.html',
  styleUrl: './profile.scss',
})
export class CarrierProfilePage {
  protected readonly statusLabel = VERIFICATION_LABEL;
  protected readonly documentStatusLabel = DOCUMENT_STATUS_LABEL;

  protected readonly data = signal<CarrierProfilePayload | null>(null);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly uploading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly uploadError = signal<string | null>(null);
  protected readonly busyDocumentId = signal<number | null>(null);

  protected readonly pendingType = signal<string>('abn');
  protected readonly pendingExpiry = signal<string>('');
  protected readonly pendingFile = signal<File | null>(null);

  private readonly profiles = inject(CarrierProfileService);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    phone: [''],
    company_name: [''],
    abn_acn: [''],
    address_line_1: [''],
    city: [''],
    state: [''],
    postal_code: [''],
    bio: ['', [Validators.maxLength(1500)]],
    fleet_size: [null as number | null],
    service_radius_km: [null as number | null],
    insurance_provider: [''],
    insurance_policy_number: [''],
    operating_since: [null as number | null],
  });

  /** The documents list, or an empty array while it is still loading. */
  protected readonly documents = computed<VerificationDocument[]>(
    () => this.data()?.profile.verification.documents ?? [],
  );

  /** Required types with nothing approved against them yet. */
  protected readonly missing = computed(() => {
    const requirements = this.data()?.requirements;

    if (!requirements) {
      return [];
    }

    return requirements.document_types.filter((type) =>
      requirements.missing.includes(type.key),
    );
  });

  protected readonly maxUploadMb = computed(() =>
    Math.round(((this.data()?.requirements.max_upload_kb ?? 0) / 1024) * 10) / 10,
  );

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set(null);

    this.profiles.get().subscribe({
      next: (payload) => {
        this.data.set(payload);
        this.fillForm(payload);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loading.set(false);
        this.error.set(describeError(response, 'Could not load your profile.'));
      },
    });
  }

  private fillForm(payload: CarrierProfilePayload): void {
    const p = payload.profile;

    this.form.patchValue({
      name: p.name ?? '',
      phone: p.phone ?? '',
      company_name: p.company_name ?? '',
      abn_acn: p.abn_acn ?? '',
      address_line_1: p.address_line_1 ?? '',
      city: p.city ?? '',
      state: p.state ?? '',
      postal_code: p.postal_code ?? '',
      bio: p.bio ?? '',
      fleet_size: p.fleet_size,
      service_radius_km: p.service_radius_km,
      insurance_provider: p.insurance_provider ?? '',
      insurance_policy_number: p.insurance_policy_number ?? '',
      operating_since: p.operating_since,
    });
  }

  protected save(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.notice.set(null);

    // Blank text boxes are sent as null rather than '': the API treats null as
    // "not set", and an empty string would store a value that looks present.
    const raw = this.form.getRawValue();
    const changes = Object.fromEntries(
      Object.entries(raw).map(([key, value]) => [key, value === '' ? null : value]),
    );

    this.profiles.update(changes).subscribe({
      next: (response) => {
        this.saving.set(false);
        this.data.set(response.data);
        this.fillForm(response.data);
        this.notice.set(response.message || 'Profile updated.');
      },
      error: (response: HttpErrorResponse) => {
        this.saving.set(false);
        this.error.set(describeError(response, 'Could not save those changes.'));
      },
    });
  }

  protected onFileChosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.pendingFile.set(input.files?.[0] ?? null);
    this.uploadError.set(null);
  }

  protected upload(): void {
    const file = this.pendingFile();

    if (!file) {
      this.uploadError.set('Choose a file first.');
      return;
    }

    this.uploading.set(true);
    this.uploadError.set(null);

    this.profiles.upload(this.pendingType(), file, this.pendingExpiry() || null).subscribe({
      next: (response) => {
        this.uploading.set(false);
        this.pendingFile.set(null);
        this.pendingExpiry.set('');
        this.notice.set(response.message || 'Document uploaded.');
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.uploading.set(false);
        this.uploadError.set(describeError(response, 'Could not upload that file.'));
      },
    });
  }

  protected remove(document: VerificationDocument): void {
    this.busyDocumentId.set(document.id);

    this.profiles.removeDocument(document.id).subscribe({
      next: () => {
        this.busyDocumentId.set(null);
        this.load();
      },
      error: (response: HttpErrorResponse) => {
        this.busyDocumentId.set(null);
        this.error.set(describeError(response, 'Could not remove that document.'));
      },
    });
  }

  /**
   * Fetches the file as a blob and hands it to the browser.
   *
   * A plain link cannot work: the file sits behind a policy check that needs
   * the bearer token, which only the interceptor attaches.
   */
  protected download(doc: VerificationDocument): void {
    this.busyDocumentId.set(doc.id);

    this.profiles.download(doc).subscribe({
      next: (blob) => {
        this.busyDocumentId.set(null);

        const url = URL.createObjectURL(blob);
        const link = window.document.createElement('a');
        link.href = url;
        link.download = doc.original_name ?? 'document';
        link.click();
        // Released immediately; the click has already taken its copy.
        URL.revokeObjectURL(url);
      },
      error: (response: HttpErrorResponse) => {
        this.busyDocumentId.set(null);
        this.error.set(describeError(response, 'Could not open that document.'));
      },
    });
  }

  protected typeLabel(key: string): string {
    return this.data()?.requirements.document_types.find((t) => t.key === key)?.label ?? key;
  }

  protected fileSize(bytes: number | null): string {
    if (!bytes) {
      return '';
    }

    return bytes < 1024 * 1024
      ? `${Math.round(bytes / 1024)} KB`
      : `${Math.round((bytes / 1024 / 1024) * 10) / 10} MB`;
  }
}
