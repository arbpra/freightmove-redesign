import { HttpErrorResponse } from '@angular/common/http';

import { environment } from '../../../environments/environment';

/**
 * Turns a failed request into something a person can act on.
 *
 * The API always answers with { success, message, errors }, so a response
 * without a `message` means the request never reached the API — a wrong base
 * URL, a stopped server, or a blocked origin. Saying so beats a generic
 * "please try again", which sends people hunting for a typo in their password.
 */
export function describeError(response: HttpErrorResponse, fallback: string): string {
  const message = response.error?.message;

  if (typeof message === 'string' && message.length > 0) {
    return message;
  }

  if (response.status === 0) {
    return `Could not reach the API at ${environment.apiUrl}. Is the Laravel server running?`;
  }

  // A 404 that returns HTML is the classic symptom of the app pointing at
  // itself instead of at the API.
  if (typeof response.error === 'string' && response.error.includes('<!doctype html')) {
    return `The API base URL looks wrong: ${environment.apiUrl} is serving HTML, not JSON.`;
  }

  return fallback;
}

/**
 * Flattens Laravel's { errors: { field: [messages] } } into a list.
 */
export function fieldErrors(response: HttpErrorResponse): string[] {
  return Object.values(response.error?.errors ?? {}).flat() as string[];
}
