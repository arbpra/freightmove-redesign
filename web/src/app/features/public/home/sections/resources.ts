import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { IconName } from '../../../../shared/icons';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';

interface Article {
  tag: string;
  title: string;
  readTime: string;
  icon: IconName;
}

@Component({
  selector: 'fm-resources',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, SectionHead],
  template: `
    <div class="fm-container">
      <div class="head">
        <fm-section-head
          align="start"
          eyebrow="Learn & grow"
          title="Guides that make you a *sharper shipper*"
          subtitle="What freight actually costs, what the rules require, and how to pick the right carrier."
          fmReveal
        />

        <a class="fm-btn fm-btn--ghost" routerLink="/" fragment="faq" fmReveal>
          View all resources
          <fm-icon name="arrow-right" size="14" [strokeWidth]="2.2" />
        </a>
      </div>

      <ul class="grid">
        @for (article of articles; track article.title; let i = $index) {
          <li [fmReveal]="i" [revealStep]="55">
            <a routerLink="/" fragment="faq">
              <span class="media">
                <fm-icon [name]="article.icon" size="28" [strokeWidth]="1.3" />
                <span class="tag">{{ article.tag }}</span>
              </span>
              <span class="body">
                <span class="title">{{ article.title }}</span>
                <span class="meta">
                  <span class="read">{{ article.readTime }}</span>
                  <span class="more">
                    Read
                    <fm-icon name="arrow-right" size="12" [strokeWidth]="2.4" />
                  </span>
                </span>
              </span>
            </a>
          </li>
        }
      </ul>
    </div>
  `,
  styleUrl: './resources.scss',
})
export class Resources {
  protected readonly articles: Article[] = [
    {
      tag: 'Guide',
      title: 'How much does heavy haulage cost in Australia?',
      readTime: '6 min read',
      icon: 'excavator',
    },
    {
      tag: 'Regulations',
      title: 'Oversize load regulations explained',
      readTime: '8 min read',
      icon: 'file-check',
    },
    {
      tag: 'Tips',
      title: 'How to choose the right transport carrier',
      readTime: '5 min read',
      icon: 'badge-check',
    },
    {
      tag: 'Safety',
      title: 'Transporting excavators safely in Australia',
      readTime: '7 min read',
      icon: 'shield-check',
    },
    {
      tag: 'Insights',
      title: '5 ways to reduce your freight transport costs',
      readTime: '4 min read',
      icon: 'price-tag',
    },
    {
      tag: 'Industry',
      title: 'The future of freight transport in Australia',
      readTime: '9 min read',
      icon: 'truck-fast',
    },
  ];
}
