import { Routes } from '@angular/router';

import { authGuard } from './core/guards/auth.guard';
import { guestGuard } from './core/guards/guest.guard';
import { roleGuard } from './core/guards/role.guard';

/**
 * Every dashboard area is lazy loaded and gated by role, per
 * docs/04-architecture-and-technical-design.md section 2.
 */
export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./features/public/home/home').then((m) => m.Home),
    title: 'FreightMove — Freight that moves on your schedule',
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
    path: 'shipper',
    canActivate: [authGuard, roleGuard],
    data: { roles: ['shipper'] },
    loadComponent: () =>
      import('./features/shipper/overview/overview').then((m) => m.ShipperOverview),
    title: 'Shipper dashboard — FreightMove',
  },
  {
    path: 'carrier',
    canActivate: [authGuard, roleGuard],
    data: { roles: ['carrier'] },
    loadComponent: () =>
      import('./features/carrier/overview/overview').then((m) => m.CarrierOverview),
    title: 'Carrier dashboard — FreightMove',
  },
  {
    path: 'admin',
    canActivate: [authGuard, roleGuard],
    data: { roles: ['admin'] },
    loadComponent: () => import('./features/admin/overview/overview').then((m) => m.AdminOverview),
    title: 'Admin console — FreightMove',
  },
  { path: '**', redirectTo: '' },
];
