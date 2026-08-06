import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { catchError, throwError } from 'rxjs';

import { AuthService } from '../auth/auth.service';

/**
 * Attaches the Sanctum token to API calls and signs the user out when the
 * API says the token is no longer good.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const auth = inject(AuthService);
  const token = auth.token;

  const authorised = token
    ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : request;

  return next(authorised).pipe(
    catchError((error: HttpErrorResponse) => {
      // 401 means the token is gone or expired. 403 is a live token that
      // simply lacks permission, so it must not sign the user out.
      const isLoginAttempt = request.url.endsWith('/auth/login');

      if (error.status === 401 && token && !isLoginAttempt) {
        auth.clearAndRedirect();
      }

      return throwError(() => error);
    }),
  );
};
