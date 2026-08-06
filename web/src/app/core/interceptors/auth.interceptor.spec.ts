import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { AuthService } from '../auth/auth.service';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;
  let auth: AuthService;

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });

    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
  });

  afterEach(() => controller.verify());

  it('sends no Authorization header while signed out', () => {
    http.get('/api/v1/thing').subscribe();

    const request = controller.expectOne('/api/v1/thing');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({});
  });

  it('attaches the bearer token once signed in', () => {
    localStorage.setItem('freightmove.token', 'tok_123');
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);

    http.get('/api/v1/thing').subscribe();

    const request = controller.expectOne('/api/v1/thing');
    expect(request.request.headers.get('Authorization')).toBe('Bearer tok_123');
    request.flush({});
  });

  it('signs the user out on a 401', () => {
    localStorage.setItem('freightmove.token', 'tok_123');
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);

    http.get('/api/v1/thing').subscribe({ error: () => undefined });

    controller
      .expectOne('/api/v1/thing')
      .flush({ message: 'Unauthenticated.' }, { status: 401, statusText: 'Unauthorized' });

    expect(auth.isAuthenticated()).toBe(false);
  });

  it('keeps the user signed in on a 403', () => {
    localStorage.setItem('freightmove.token', 'tok_123');
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);

    http.get('/api/v1/admin/overview').subscribe({ error: () => undefined });

    controller
      .expectOne('/api/v1/admin/overview')
      .flush({ message: 'Forbidden.' }, { status: 403, statusText: 'Forbidden' });

    // A 403 is a permission problem, not a bad token.
    expect(auth.isAuthenticated()).toBe(true);
  });
});
