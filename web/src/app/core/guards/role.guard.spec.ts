import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  RouterStateSnapshot,
  UrlTree,
  provideRouter,
} from '@angular/router';

import { User } from '../auth/auth.models';
import { AuthService } from '../auth/auth.service';
import { authGuard } from './auth.guard';
import { roleGuard } from './role.guard';

function storeUser(role: User['role']): void {
  localStorage.setItem('freightmove.token', 'tok_123');
  localStorage.setItem(
    'freightmove.user',
    JSON.stringify({
      id: 1,
      name: 'Test',
      email: 't@example.com',
      phone: null,
      role,
      status: 'active',
      avatar_url: null,
      timezone: 'Australia/Sydney',
      locale: 'en',
      email_verified: true,
  should_update_password: false,
      created_at: null,
    }),
  );
}

function routeWithRoles(roles: string[]): ActivatedRouteSnapshot {
  return { data: { roles } } as unknown as ActivatedRouteSnapshot;
}

const state = { url: '/admin' } as RouterStateSnapshot;

describe('role and auth guards', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });
  });

  it('lets a matching role through', () => {
    storeUser('admin');
    const result = TestBed.runInInjectionContext(() => roleGuard(routeWithRoles(['admin']), state));

    expect(result).toBe(true);
  });

  it('redirects a mismatched role to its own dashboard', () => {
    storeUser('carrier');
    const result = TestBed.runInInjectionContext(() => roleGuard(routeWithRoles(['admin']), state));

    expect(result instanceof UrlTree).toBe(true);
    expect((result as UrlTree).toString()).toBe('/carrier');
  });

  it('sends a guest to login, remembering where they were going', () => {
    const result = TestBed.runInInjectionContext(() =>
      authGuard(routeWithRoles([]), { url: '/shipper' } as RouterStateSnapshot),
    );

    expect(result instanceof UrlTree).toBe(true);
    expect((result as UrlTree).toString()).toContain('/login');
    expect((result as UrlTree).toString()).toContain('redirect=%2Fshipper');
  });

  it('lets a signed-in user past the auth guard', () => {
    storeUser('shipper');
    TestBed.inject(AuthService);

    const result = TestBed.runInInjectionContext(() => authGuard(routeWithRoles([]), state));
    expect(result).toBe(true);
  });
});
