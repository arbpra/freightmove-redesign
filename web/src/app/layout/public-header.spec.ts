import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { PublicHeader } from './public-header';

describe('PublicHeader', () => {
  beforeEach(async () => {
    localStorage.clear();

    await TestBed.configureTestingModule({
      imports: [PublicHeader],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
  });

  it('shows the brand', () => {
    const fixture = TestBed.createComponent(PublicHeader);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('.logo')?.textContent).toContain('FREIGHT');
  });

  it('offers log in and sign up while signed out', () => {
    const fixture = TestBed.createComponent(PublicHeader);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Log in');
    expect(compiled.textContent).toContain('Get started');
    expect(compiled.textContent).not.toContain('Sign out');
  });

  /**
   * The dropdown used to close on the first `mouseleave`, which fired while the
   * cursor was still crossing the gap between the trigger and the panel — so
   * the menu could be opened but never reached.
   */
  it('keeps the dropdown open while the cursor travels to it', fakeAsync(() => {
    const fixture = TestBed.createComponent(PublicHeader);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    const item = compiled.querySelector<HTMLElement>('.nav-item')!;

    item.dispatchEvent(new MouseEvent('mouseenter'));
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown')).toBeTruthy();

    // Leaving starts a grace period rather than closing outright.
    item.dispatchEvent(new MouseEvent('mouseleave'));
    tick(50);
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown'))
      .withContext('still open during the grace period')
      .toBeTruthy();

    // Arriving at the panel cancels the close.
    item.dispatchEvent(new MouseEvent('mouseenter'));
    tick(500);
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown'))
      .withContext('re-entering cancels the pending close')
      .toBeTruthy();
  }));

  it('closes once the cursor has genuinely left', fakeAsync(() => {
    const fixture = TestBed.createComponent(PublicHeader);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    const item = compiled.querySelector<HTMLElement>('.nav-item')!;

    item.dispatchEvent(new MouseEvent('mouseenter'));
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown')).toBeTruthy();

    item.dispatchEvent(new MouseEvent('mouseleave'));
    tick(500);
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown')).toBeNull();
  }));

  it('opens a nav group on click and closes it again', () => {
    const fixture = TestBed.createComponent(PublicHeader);
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    const trigger = compiled.querySelector<HTMLButtonElement>('.nav-trigger');
    expect(trigger).toBeTruthy();

    trigger!.click();
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown')).toBeTruthy();
    expect(trigger!.getAttribute('aria-expanded')).toBe('true');

    trigger!.click();
    fixture.detectChanges();
    expect(compiled.querySelector('.dropdown')).toBeNull();
  });
});
