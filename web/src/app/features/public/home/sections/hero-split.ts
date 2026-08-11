import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterLink } from '@angular/router';

import { Icon } from '../../../../shared/icon';
import { Ripple } from '../../../../shared/ripple.directive';

/**
 * The split hero: shippers on the blue half, carriers on the red half, with the
 * trust badge on the seam. The halves interlock via complementary clip-paths on
 * desktop and stack into two full-width blocks below 64rem.
 */
@Component({
  selector: 'fm-hero-split',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [RouterLink, Icon, Ripple],
  templateUrl: './hero-split.html',
  styleUrl: './hero-split.scss',
})
export class HeroSplit {
  protected readonly shipperPoints = [
    'Free to post your load',
    'Compare quotes & save more',
    'Australia wide coverage',
    'Secure, reliable & hassle-free',
  ];

  protected readonly carrierPoints = [
    'Access thousands of active loads',
    'Choose loads that suit you',
    'Get paid & keep rolling',
    'Grow your transport business',
  ];
}
