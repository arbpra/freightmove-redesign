import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { IconName } from '../../../../shared/icons';
import { Reveal } from '../../../../shared/reveal.directive';
import { Ripple } from '../../../../shared/ripple.directive';
import { FREIGHT_TYPES } from './freight.data';

/**
 * The quote-capture card that straddles the hero.
 *
 * Quoting needs an account, so the form hands its values to registration as
 * query params — onboarding prefills the first job from them rather than making
 * the visitor type everything twice.
 */
@Component({
  selector: 'fm-quote-search',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, Icon, Reveal, Ripple],
  templateUrl: './quote-search.html',
  styleUrl: './quote-search.scss',
})
export class QuoteSearch {
  protected readonly freightTypes = FREIGHT_TYPES;

  protected readonly badges: { label: string; icon: IconName }[] = [
    { label: "It's Free", icon: 'price-tag' },
    { label: 'No Obligation', icon: 'file-check' },
    { label: 'Verified Carriers', icon: 'badge-check' },
    { label: 'Australia Wide', icon: 'globe' },
    { label: 'Fast Quotes', icon: 'zap' },
    { label: 'Secure & Safe', icon: 'lock' },
  ];

  private readonly router = inject(Router);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    origin: ['', Validators.required],
    destination: ['', Validators.required],
    freightType: [''],
    weight: [''],
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.router.navigate(['/register'], {
      queryParams: { role: 'shipper', ...this.form.getRawValue() },
    });
  }
}
