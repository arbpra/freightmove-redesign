import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { Seo } from '../../../core/seo/seo.service';
import { describeError } from '../../../core/http/describe-error';
import { CONTACT_PHONE, CONTACT_PHONE_HREF } from '../../../layout/public-nav';
import { Icon } from '../../../shared/icon';
import { IconName } from '../../../shared/icons';
import { Reveal } from '../../../shared/reveal.directive';
import { Ripple } from '../../../shared/ripple.directive';
import { Spotlight } from '../../../shared/spotlight.directive';

interface ContactChannel {
  icon: IconName;
  label: string;
  value: string;
  note: string;
  href?: string;
}

@Component({
  selector: 'fm-contact',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule, RouterLink, Icon, Reveal, Ripple, Spotlight],
  templateUrl: './contact.html',
  styleUrl: './contact.scss',
})
export class Contact {
  protected readonly phone = CONTACT_PHONE;
  protected readonly phoneHref = CONTACT_PHONE_HREF;
  protected readonly email = 'info@freightmove.au';

  protected readonly channels: ContactChannel[] = [
    {
      icon: 'phone',
      label: 'Call us',
      value: CONTACT_PHONE,
      note: 'Monday to Friday, 7am–7pm AEST',
      href: CONTACT_PHONE_HREF,
    },
    {
      icon: 'mail',
      label: 'Email us',
      value: 'info@freightmove.au',
      note: 'We reply within one business hour',
      href: 'mailto:info@freightmove.au',
    },
    {
      icon: 'map-pin',
      label: 'Where we operate',
      value: 'Australia wide',
      note: 'Metro, regional and remote sites',
    },
    {
      icon: 'clock',
      label: 'Freight support',
      value: 'Seven days a week',
      note: 'Live jobs monitored outside office hours',
    },
  ];

  protected readonly enquiryTypes = [
    'General enquiry',
    'Quote or pricing',
    'An existing booking',
    'Becoming a verified carrier',
    'Partnerships and media',
    'Feedback or a complaint',
  ];

  protected readonly reassurances: { icon: IconName; title: string; body: string }[] = [
    {
      icon: 'zap',
      title: 'Fast answers',
      body: 'Enquiries sent during business hours are answered within one hour, not the next day.',
    },
    {
      icon: 'badge-check',
      title: 'Real operators',
      body: 'You speak to people who know freight and lanes, never an offshore script.',
    },
    {
      icon: 'lock',
      title: 'Kept private',
      body: 'Your details are used to answer your enquiry and are never sold or shared.',
    },
  ];

  protected readonly busy = signal(false);
  protected readonly sent = signal(false);
  protected readonly error = signal<string | null>(null);

  private readonly http = inject(HttpClient);

  protected readonly form = inject(FormBuilder).nonNullable.group({
    name: ['', [Validators.required, Validators.maxLength(120)]],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    role: ['shipper'],
    subject: [this.enquiryTypes[0]],
    message: ['', [Validators.required, Validators.minLength(20), Validators.maxLength(2000)]],
    // Honeypot: hidden from people and from screen readers, so anything that
    // arrives filled came from a script. The API discards those silently.
    company_website: [''],
  });

  constructor() {
    inject(Seo).apply({
      title: 'Contact FreightMove | Freight Support Australia Wide',
      description:
        'Talk to the FreightMove team about moving freight anywhere in Australia. Call 1300 123 456, email us, or send an enquiry and we will reply within one business hour.',
      path: '/contact-us',
      structuredData: {
        '@context': 'https://schema.org',
        '@type': 'ContactPage',
        name: 'Contact FreightMove',
        url: `${environment.siteUrl}/contact-us`,
        mainEntity: {
          '@type': 'Organization',
          name: 'FreightMove',
          url: environment.siteUrl,
          areaServed: { '@type': 'Country', name: 'Australia' },
          contactPoint: [
            {
              '@type': 'ContactPoint',
              telephone: '+61-1300-123-456',
              email: 'info@freightmove.au',
              contactType: 'customer service',
              areaServed: 'AU',
              availableLanguage: 'English',
            },
          ],
        },
      },
    });
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.busy.set(true);
    this.error.set(null);

    this.http.post(`${environment.apiUrl}/contact`, this.form.getRawValue()).subscribe({
      next: () => {
        this.busy.set(false);
        this.sent.set(true);
        this.form.reset({ role: 'shipper', subject: this.enquiryTypes[0], company_website: '' });
      },
      error: (response: HttpErrorResponse) => {
        this.busy.set(false);
        this.error.set(
          describeError(
            response,
            `We could not send that just now. Please call ${CONTACT_PHONE} or email ${this.email}.`,
          ),
        );
      },
    });
  }

  /** True once a control has been touched and is failing validation. */
  protected invalid(control: keyof typeof this.form.controls): boolean {
    const field = this.form.controls[control];
    return field.invalid && field.touched;
  }
}
