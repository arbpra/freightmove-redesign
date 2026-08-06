import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../environments/environment';
import { User } from './auth.models';
import { AuthService } from './auth.service';

const shipper: User = {
  id: 1,
  name: 'Jordan Blake',
  email: 'shipper@freightmove.test',
  phone: null,
  role: 'shipper',
  status: 'active',
  avatar_url: null,
  timezone: 'Australia/Sydney',
  locale: 'en',
  email_verified: true,
  created_at: null,
};

describe('AuthService', () => {
  let service: AuthService;
  let http: HttpTestingController;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });

    service = TestBed.inject(AuthService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('starts signed out', () => {
    expect(service.isAuthenticated()).toBe(false);
    expect(service.role()).toBeNull();
  });

  it('stores the token and user after login', () => {
    service.login({ email: shipper.email, password: 'password' }).subscribe();

    http.expectOne(`${environment.apiUrl}/auth/login`).flush({
      success: true,
      data: { token: 'tok_123', user: shipper },
      message: '',
    });

    expect(service.isAuthenticated()).toBe(true);
    expect(service.token).toBe('tok_123');
    expect(service.user()?.name).toBe('Jordan Blake');
    expect(localStorage.getItem('freightmove.token')).toBe('tok_123');
  });

  it('routes each role to its own dashboard', () => {
    service.login({ email: shipper.email, password: 'password' }).subscribe();
    http.expectOne(`${environment.apiUrl}/auth/login`).flush({
      success: true,
      data: { token: 'tok_123', user: { ...shipper, role: 'admin' } },
      message: '',
    });

    expect(service.homeRoute()).toBe('/admin');
  });

  it('clears local state even when the logout call fails', () => {
    service.login({ email: shipper.email, password: 'password' }).subscribe();
    http.expectOne(`${environment.apiUrl}/auth/login`).flush({
      success: true,
      data: { token: 'tok_123', user: shipper },
      message: '',
    });

    service.logout();
    http
      .expectOne(`${environment.apiUrl}/auth/logout`)
      .error(new ProgressEvent('network error'));

    expect(service.isAuthenticated()).toBe(false);
    expect(localStorage.getItem('freightmove.token')).toBeNull();
  });

  it('recovers from a corrupted cached user', () => {
    localStorage.setItem('freightmove.user', '{not json');

    // A fresh injector re-reads storage.
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    });

    const fresh = TestBed.inject(AuthService);
    expect(fresh.user()).toBeNull();

    http = TestBed.inject(HttpTestingController);
  });
});
