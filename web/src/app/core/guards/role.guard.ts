import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { UserRole } from '../auth/auth.models';
import { AuthService } from '../auth/auth.service';

/**
 * Restricts a route to specific roles. Read from route data:
 *
 *   { path: 'admin', canActivate: [authGuard, roleGuard], data: { roles: ['admin'] } }
 *
 * This is a usability measure, not the security boundary — the API enforces
 * the same rule with its own `role:` middleware.
 */
export const roleGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  const allowed = (route.data['roles'] ?? []) as UserRole[];
  const role = auth.role();

  if (role && allowed.includes(role)) {
    return true;
  }

  // Send them to their own dashboard rather than a dead end.
  return router.parseUrl(auth.homeRoute());
};
