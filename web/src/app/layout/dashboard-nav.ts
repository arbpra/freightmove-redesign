import { IconName } from '../shared/icons';

export interface DashboardLink {
  path: string;
  label: string;
  icon: IconName;
  /** Shown on the mobile tab bar, where "Verification queue" will not fit. */
  short?: string;
}

/**
 * What each role can reach from the dashboard shell.
 *
 * One source for the desktop rail and the mobile tab bar, so the two can never
 * drift. Only destinations that exist are listed — a link to a screen that has
 * not been built is worse than no link at all.
 */
export const DASHBOARD_NAV: Record<string, DashboardLink[]> = {
  shipper: [
    { path: '/shipper', label: 'Dashboard', icon: 'home' },
    { path: '/shipper/jobs', label: 'My loads', icon: 'boxes', short: 'Loads' },
    { path: '/shipper/jobs/new', label: 'Post a load', icon: 'plus', short: 'Post' },
    { path: '/messages', label: 'Messages', icon: 'mail' },
  ],
  carrier: [
    { path: '/carrier', label: 'Dashboard', icon: 'home' },
    { path: '/carrier/board', label: 'Load board', icon: 'truck', short: 'Board' },
    { path: '/messages', label: 'Messages', icon: 'mail' },
    { path: '/carrier/profile', label: 'Profile', icon: 'badge-check', short: 'Profile' },
    { path: '/carrier/subscription', label: 'Subscription', icon: 'zap', short: 'Plan' },
  ],
  admin: [
    { path: '/admin', label: 'Dashboard', icon: 'home' },
    {
      path: '/admin/verifications',
      label: 'Verification queue',
      icon: 'clipboard',
      short: 'Queue',
    },
    { path: '/admin/users', label: 'Accounts', icon: 'users' },
    { path: '/admin/subscriptions', label: 'Subscriptions', icon: 'zap', short: 'Subs' },
  ],
};
