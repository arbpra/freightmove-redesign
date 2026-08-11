import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';
import { FREIGHT_TYPES } from './freight.data';

@Component({
  selector: 'fm-freight-types',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, SectionHead],
  template: `
    <div class="fm-container">
      <fm-section-head
        eyebrow="Freight we handle"
        title="From a single pallet to an *oversize load*"
        subtitle="Twelve specialist categories, each with carriers who run that equipment every week."
        fmReveal
      />

      <ul class="grid">
        @for (type of types; track type.slug; let i = $index) {
          <li [fmReveal]="i" [revealStep]="40">
            <!-- The card now opens that category's page rather than jumping
                 straight to sign-up: a visitor still deciding wants to read
                 about the freight first, and each page is a search entry
                 point in its own right. -->
            <a [routerLink]="'/' + type.slug">
              <span class="media">
                @if (type.image) {
                  <!-- alt is empty on purpose: the visible label right below
                       already names the freight type, so describing the photo
                       would make screen readers announce it twice. -->
                  <img
                    [src]="type.image"
                    alt=""
                    width="800"
                    height="500"
                    loading="lazy"
                    decoding="async"
                  />
                } @else {
                  <fm-icon [name]="type.icon" size="32" [strokeWidth]="1.3" />
                }
              </span>
              <span class="body">
                <span class="label">{{ type.label }}</span>
                <span class="blurb">{{ type.blurb }}</span>
              </span>
            </a>
          </li>
        }
      </ul>

      <div class="more" fmReveal>
        <a class="fm-btn fm-btn--ghost" routerLink="/register">
          View all services
          <fm-icon name="arrow-right" size="14" [strokeWidth]="2.2" />
        </a>
      </div>
    </div>
  `,
  styleUrl: './freight-types.scss',
})
export class FreightTypes {
  protected readonly types = FREIGHT_TYPES;
}
