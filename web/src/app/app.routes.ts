import { Routes } from '@angular/router';

import { authGuard } from './core/guards/auth.guard';
import { guestGuard } from './core/guards/guest.guard';
import { roleGuard } from './core/guards/role.guard';
import {
  FREIGHT_CATEGORIES,
  LEGACY_CATEGORY_REDIRECTS,
} from './features/public/freight/freight-category.data';

/**
 * One route per freight category, generated from the same list the pages read
 * their content from — so a category cannot exist without a URL, or the reverse.
 *
 * `slug` is passed through route data and bound to the component's input, which
 * keeps each URL a real entry point rather than a parameterised catch-all that
 * would also swallow every other top-level path.
 */
const freightCategoryRoutes: Routes = FREIGHT_CATEGORIES.map((category) => ({
  path: category.slug,
  data: { slug: category.slug },
  loadComponent: () =>
    import('./features/public/freight/freight-category').then((m) => m.FreightCategoryPage),
  title: category.metaTitle,
}));

/**
 * The previous site's category URLs, which are indexed and may carry links.
 * Redirected rather than left to 404 so their ranking transfers.
 */
const legacyCategoryRedirects: Routes = Object.entries(LEGACY_CATEGORY_REDIRECTS).map(
  ([from, to]) => ({ path: from, redirectTo: `/${to}`, pathMatch: 'full' as const }),
);

/**
 * Marketing and auth pages sit under the public chrome; every dashboard area is
 * lazy loaded and gated by role, per
 * docs/04-architecture-and-technical-design.md section 2.
 */
export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./layout/public-layout').then((m) => m.PublicLayout),
    children: [
      {
        path: '',
        loadComponent: () => import('./features/public/home/home').then((m) => m.Home),
        title: 'FreightMove — Australia’s trusted freight marketplace',
      },
      {
        // Public: browsing the board needs no account, only quoting does.
        path: 'load-board',
        loadComponent: () =>
          import('./features/public/loads/public-loads').then((m) => m.PublicLoads),
        title: 'Live Freight Load Board Australia | FreightMove',
      },
      ...freightCategoryRoutes,
      ...legacyCategoryRedirects,
      {
        // Same path as the live site, so existing links and any indexed
        // results keep working.
        path: 'carriers-subscription',
        loadComponent: () => import('./features/public/pricing/pricing').then((m) => m.Pricing),
        title: 'Carrier Subscriptions | FreightMove',
      },
      {
        path: 'contact-us',
        loadComponent: () => import('./features/public/contact/contact').then((m) => m.Contact),
        title: 'Contact FreightMove | Freight Support Australia Wide',
      },
      {
        path: 'login',
        canActivate: [guestGuard],
        loadComponent: () => import('./features/auth/login/login').then((m) => m.Login),
        title: 'Sign in — FreightMove',
      },
      {
        path: 'register',
        canActivate: [guestGuard],
        loadComponent: () => import('./features/auth/register/register').then((m) => m.Register),
        title: 'Create an account — FreightMove',
      },
      {
        path: 'forgot-password',
        canActivate: [guestGuard],
        loadComponent: () =>
          import('./features/auth/forgot-password/forgot-password').then((m) => m.ForgotPassword),
        title: 'Reset your password — FreightMove',
      },
      {
        // The token is in the path, matching the link built by
        // ResetPassword::createUrlUsing on the API side.
        path: 'reset-password/:token',
        canActivate: [guestGuard],
        loadComponent: () =>
          import('./features/auth/reset-password/reset-password').then((m) => m.ResetPassword),
        title: 'Choose a new password — FreightMove',
      },
    ],
  },
  {
    path: '',
    loadComponent: () => import('./layout/dashboard-layout').then((m) => m.DashboardLayout),
    canActivate: [authGuard],
    children: [
      {
        path: 'shipper',
        canActivate: [roleGuard],
        data: { roles: ['shipper'] },
        children: [
          {
            path: '',
            loadComponent: () =>
              import('./features/shipper/overview/overview').then((m) => m.ShipperOverview),
            title: 'Shipper dashboard — FreightMove',
          },
          {
            // Declared before ':id' so the literal segment always wins.
            path: 'jobs/new',
            loadComponent: () =>
              import('./features/shipper/jobs/job-form').then((m) => m.JobForm),
            title: 'Post a load — FreightMove',
          },
          {
            path: 'jobs',
            loadComponent: () =>
              import('./features/shipper/jobs/job-list').then((m) => m.JobList),
            title: 'My loads — FreightMove',
          },
          {
            path: 'jobs/:id/quotes',
            loadComponent: () =>
              import('./features/shipper/jobs/job-quotes').then((m) => m.JobQuotes),
            title: 'Quotes received — FreightMove',
          },
        ],
      },
      {
        // Both roles message, so this sits outside the role groups. The thread
        // id is in the URL so a conversation is linkable and survives a reload.
        path: 'messages',
        loadComponent: () => import('./features/messages/messages').then((m) => m.Messages),
        title: 'Messages — FreightMove',
      },
      {
        path: 'messages/:id',
        loadComponent: () => import('./features/messages/messages').then((m) => m.Messages),
        title: 'Messages — FreightMove',
      },
      {
        // Both parties review each other, and the carrier has no "my loads"
        // page to hang it off, so this is its own role-agnostic route.
        path: 'jobs/:id/review',
        loadComponent: () =>
          import('./features/reviews/review-page').then((m) => m.ReviewPage),
        title: 'Reviews — FreightMove',
      },
      {
        // Available to every signed-in role, not just shippers.
        path: 'account/password',
        loadComponent: () =>
          import('./features/auth/change-password/change-password').then((m) => m.ChangePassword),
        title: 'Change your password — FreightMove',
      },
      {
        path: 'carrier',
        canActivate: [roleGuard],
        data: { roles: ['carrier'] },
        children: [
          {
            path: '',
            loadComponent: () =>
              import('./features/carrier/overview/overview').then((m) => m.CarrierOverview),
            title: 'Carrier dashboard — FreightMove',
          },
          {
            path: 'board',
            loadComponent: () => import('./features/carrier/board/board').then((m) => m.LoadBoard),
            title: 'Load board — FreightMove',
          },
          {
            path: 'profile',
            loadComponent: () =>
              import('./features/carrier/profile/profile').then((m) => m.CarrierProfilePage),
            title: 'Your profile — FreightMove',
          },
          {
            // Declared before ':...' siblings so the literal segment wins.
            // PayPal returns here with ?token=<order id>.
            path: 'subscription/return',
            loadComponent: () =>
              import('./features/carrier/subscription/payment-return').then(
                (m) => m.PaymentReturn,
              ),
            title: 'Confirming payment — FreightMove',
          },
          {
            path: 'subscription',
            loadComponent: () =>
              import('./features/carrier/subscription/subscription').then(
                (m) => m.CarrierSubscription,
              ),
            title: 'Subscription — FreightMove',
          },
        ],
      },
      {
        path: 'admin',
        canActivate: [roleGuard],
        data: { roles: ['admin'] },
        children: [
          {
            path: '',
            loadComponent: () =>
              import('./features/admin/overview/overview').then((m) => m.AdminOverview),
            title: 'Admin console — FreightMove',
          },
          {
            path: 'verifications',
            loadComponent: () =>
              import('./features/admin/verifications/verifications').then(
                (m) => m.AdminVerifications,
              ),
            title: 'Verification queue — FreightMove',
          },
          {
            path: 'users',
            loadComponent: () =>
              import('./features/admin/users/admin-users').then((m) => m.AdminUsers),
            title: 'Accounts — FreightMove',
          },
          {
            path: 'subscriptions',
            loadComponent: () =>
              import('./features/admin/subscriptions/admin-subscriptions').then(
                (m) => m.AdminSubscriptions,
              ),
            title: 'Subscriptions — FreightMove',
          },
        ],
      },
    ],
  },
  { path: '**', redirectTo: '' },
];
