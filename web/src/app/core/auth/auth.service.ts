import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, tap } from 'rxjs';

import { environment } from '../../../environments/environment';
import {
  ApiEnvelope,
  ChangePasswordRequest,
  AuthPayload,
  HOME_ROUTE_FOR_ROLE,
  LoginRequest,
  RegisterRequest,
  User,
  UserRole,
} from './auth.models';

const TOKEN_KEY = 'freightmove.token';
const USER_KEY = 'freightmove.user';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly _user = signal<User | null>(readStoredUser());
  private readonly _token = signal<string | null>(localStorage.getItem(TOKEN_KEY));

  readonly user = this._user.asReadonly();
  readonly isAuthenticated = computed(() => this._token() !== null);
  readonly role = computed<UserRole | null>(() => this._user()?.role ?? null);

  /** Where this user's dashboard lives. */
  readonly homeRoute = computed(() => {
    const role = this.role();
    return role ? HOME_ROUTE_FOR_ROLE[role] : '/';
  });

  get token(): string | null {
    return this._token();
  }

  login(credentials: LoginRequest): Observable<ApiEnvelope<AuthPayload>> {
    return this.http
      .post<ApiEnvelope<AuthPayload>>(`${environment.apiUrl}/auth/login`, credentials)
      .pipe(tap((response) => this.persist(response.data)));
  }

  register(details: RegisterRequest): Observable<ApiEnvelope<AuthPayload>> {
    return this.http
      .post<ApiEnvelope<AuthPayload>>(`${environment.apiUrl}/auth/register`, details)
      .pipe(tap((response) => this.persist(response.data)));
  }

  /**
   * Asks for a reset link.
   *
   * The API answers the same way whether or not the address is registered, so
   * the page must not treat a success as proof the account exists.
   */
  forgotPassword(email: string): Observable<ApiEnvelope<null>> {
    return this.http.post<ApiEnvelope<null>>(`${environment.apiUrl}/auth/forgot-password`, {
      email,
    });
  }

  /** Completes a reset using the token from the emailed link. */
  resetPassword(details: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Observable<ApiEnvelope<null>> {
    return this.http.post<ApiEnvelope<null>>(`${environment.apiUrl}/auth/reset-password`, details);
  }

  /**
   * Sets a new password for the signed-in user.
   *
   * Used by the migration prompt shown to accounts brought over from the old
   * site. The API revokes every other session but keeps this one, so the user
   * is not signed out mid-flow; the refreshed user clears the prompt.
   */
  changePassword(details: ChangePasswordRequest): Observable<ApiEnvelope<{ user: User }>> {
    return this.http
      .put<ApiEnvelope<{ user: User }>>(`${environment.apiUrl}/auth/password`, details)
      .pipe(tap((response) => this.setUser(response.data.user)));
  }

  /** True while an imported account has yet to choose a password here. */
  readonly shouldUpdatePassword = computed(() => this.user()?.should_update_password === true);

  /** Re-reads the user from the API, e.g. after a page refresh. */
  refreshUser(): Observable<ApiEnvelope<User>> {
    return this.http
      .get<ApiEnvelope<User>>(`${environment.apiUrl}/auth/me`)
      .pipe(tap((response) => this.setUser(response.data)));
  }

  logout(): void {
    // Clear locally either way: a failed request must not strand the user
    // in a signed-in looking state.
    this.http.post(`${environment.apiUrl}/auth/logout`, {}).subscribe({
      next: () => this.clearAndRedirect(),
      error: () => this.clearAndRedirect(),
    });
  }

  /** Called by the interceptor when the API rejects the token. */
  clearAndRedirect(): void {
    this.clear();
    void this.router.navigate(['/login']);
  }

  private persist(payload: AuthPayload): void {
    localStorage.setItem(TOKEN_KEY, payload.token);
    this._token.set(payload.token);
    this.setUser(payload.user);
  }

  private setUser(user: User): void {
    localStorage.setItem(USER_KEY, JSON.stringify(user));
    this._user.set(user);
  }

  private clear(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    this._token.set(null);
    this._user.set(null);
  }
}

/**
 * The cached user makes the first paint after a refresh instant. It is a
 * convenience only — every protected call is still authorised server-side.
 */
function readStoredUser(): User | null {
  const raw = localStorage.getItem(USER_KEY);
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as User;
  } catch {
    localStorage.removeItem(USER_KEY);
    return null;
  }
}
