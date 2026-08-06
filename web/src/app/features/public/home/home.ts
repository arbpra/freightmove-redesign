import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';

import { AuthService } from '../../../core/auth/auth.service';

@Component({
  selector: 'fm-home',
  imports: [RouterLink],
  template: `
    <section class="hero">
      <h1>Freight that moves on your schedule</h1>
      <p class="muted">
        Post a load, compare quotes from verified carriers, and book the right truck — without
        the phone tag.
      </p>

      @if (auth.isAuthenticated()) {
        <a class="button" [routerLink]="auth.homeRoute()">Go to my dashboard</a>
      } @else {
        <div class="actions">
          <a class="button" routerLink="/register">Post a freight job</a>
          <a class="button ghost" routerLink="/register">Become a carrier</a>
        </div>
      }
    </section>

    <section class="placeholder muted">
      Marketing pages (How It Works, Services, Industries, Pricing, FAQ, Blog, Contact) arrive in
      Phase 3.
    </section>
  `,
  styleUrl: './home.scss',
})
export class Home {
  protected readonly auth = inject(AuthService);
}
