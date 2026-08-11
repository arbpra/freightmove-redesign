import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { Reveal } from '../../../../shared/reveal.directive';
import { SectionHead } from '../../../../shared/section-head';

/**
 * City positions inside the map's `0 0 100 84` viewBox.
 *
 * Projected linearly from real coordinates: x = (longitude - 112) * 100/42 and
 * y = (latitude - 9) * 84/35, covering 112°E–154°E and 9°S–44°S. Equirectangular
 * rather than a true projection — at this size the difference is invisible, and
 * it keeps the pins and the coastline on one consistent grid, so a new city can
 * be added with the same two sums.
 */
const CITIES: Record<string, { x: number; y: number; side: 'left' | 'right' }> = {
  // `side` places the label so it stays inside the map. Perth sits hard against
  // the west coast, so its label runs inland; the rest read outward to sea.
  Darwin: { x: 44.9, y: 8.3, side: 'left' },
  Townsville: { x: 82.9, y: 24.7, side: 'right' },
  Brisbane: { x: 97.6, y: 44.4, side: 'right' },
  Sydney: { x: 93.3, y: 59.7, side: 'right' },
  Melbourne: { x: 78.5, y: 69.1, side: 'right' },
  Adelaide: { x: 63.3, y: 62.2, side: 'left' },
  Perth: { x: 9.3, y: 55.2, side: 'right' },
};

interface Lane {
  from: string;
  to: string;
}

@Component({
  selector: 'fm-popular-routes',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Reveal, SectionHead],
  templateUrl: './popular-routes.html',
  styleUrl: './popular-routes.scss',
})
export class PopularRoutes {
  protected readonly lanes: Lane[] = [
    { from: 'Sydney', to: 'Melbourne' },
    { from: 'Brisbane', to: 'Perth' },
    { from: 'Melbourne', to: 'Brisbane' },
    { from: 'Adelaide', to: 'Darwin' },
    { from: 'Perth', to: 'Sydney' },
    { from: 'Brisbane', to: 'Townsville' },
  ];

  /** Every city touched by a lane, so each gets exactly one pin. */
  protected readonly pins = [...new Set(this.lanes.flatMap((lane) => [lane.from, lane.to]))].map(
    (name) => ({ name, ...CITIES[name] }),
  );

  protected point(city: string): { x: number; y: number; side: 'left' | 'right' } {
    return CITIES[city];
  }

  /**
   * Bows each lane into an arc instead of a straight chord. Control point is
   * the midpoint pushed perpendicular to the line, so long hauls curve more
   * than short ones and parallel lanes stay visually separable.
   */
  protected arc(lane: Lane): string {
    const a = CITIES[lane.from];
    const b = CITIES[lane.to];
    const midX = (a.x + b.x) / 2;
    const midY = (a.y + b.y) / 2;
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const bow = 0.16;

    return `M ${a.x} ${a.y} Q ${midX - dy * bow} ${midY + dx * bow} ${b.x} ${b.y}`;
  }
}
