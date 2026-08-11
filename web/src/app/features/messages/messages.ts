import { HttpErrorResponse } from '@angular/common/http';
import {
  AfterViewChecked,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';

import { describeError } from '../../core/http/describe-error';
import { NotificationService } from '../../core/notifications/notification.service';
import { Icon } from '../../shared/icon';
import { ConversationSummary, MessagesService, Thread } from './messages.service';

/**
 * The messaging centre: conversation list beside the active thread
 * (docs/03-ui-ux-plan.md section 4.4).
 *
 * On mobile the two panes swap rather than sit side by side — the list is
 * shown until a thread is chosen, and a back arrow returns to it.
 */
@Component({
  selector: 'fm-messages',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, Icon],
  templateUrl: './messages.html',
  styleUrl: './messages.scss',
})
export class Messages implements AfterViewChecked {
  protected readonly conversations = signal<ConversationSummary[]>([]);
  protected readonly thread = signal<Thread | null>(null);
  protected readonly loadingList = signal(true);
  protected readonly loadingThread = signal(false);
  protected readonly sending = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly draft = signal('');

  private readonly messages = inject(MessagesService);
  private readonly notifications = inject(NotificationService);
  private readonly router = inject(Router);

  private readonly scroller = viewChild<ElementRef<HTMLElement>>('scroller');
  private scrollPending = false;

  /** The thread id in the URL, so a conversation is linkable and refreshable. */
  private readonly selectedId = toSignal(
    inject(ActivatedRoute).paramMap.pipe(map((params) => Number(params.get('id')) || null)),
    { initialValue: null },
  );

  constructor() {
    this.loadList();

    // Driven by the route rather than by the click, so a pasted or refreshed
    // URL opens the same thread a click would.
    effect(() => {
      const id = this.selectedId();

      if (id === null) {
        this.thread.set(null);
        return;
      }

      this.loadThread(id);
    });
  }

  ngAfterViewChecked(): void {
    if (!this.scrollPending) {
      return;
    }

    const element = this.scroller()?.nativeElement;

    if (element) {
      element.scrollTop = element.scrollHeight;
      this.scrollPending = false;
    }
  }

  protected loadList(): void {
    this.loadingList.set(true);

    this.messages.list().subscribe({
      next: (list) => {
        this.conversations.set(list.items);
        this.loadingList.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.loadingList.set(false);
        this.error.set(describeError(response, 'Could not load your messages.'));
      },
    });
  }

  protected select(conversation: ConversationSummary): void {
    void this.router.navigate(['/messages', conversation.id]);
  }

  protected back(): void {
    void this.router.navigate(['/messages']);
  }

  private loadThread(id: number): void {
    this.loadingThread.set(true);
    this.error.set(null);

    this.messages.thread(id).subscribe({
      next: (thread) => {
        this.thread.set(thread);
        this.loadingThread.set(false);
        this.scrollPending = true;

        // Reading the thread cleared its unread messages server-side, so the
        // list badge and the bell both need to catch up.
        this.conversations.update((items) =>
          items.map((item) => (item.id === id ? { ...item, unread_count: 0 } : item)),
        );
        this.notifications.refreshCount();
      },
      error: (response: HttpErrorResponse) => {
        this.loadingThread.set(false);
        this.error.set(describeError(response, 'Could not open that conversation.'));
      },
    });
  }

  protected send(): void {
    const thread = this.thread();
    const body = this.draft().trim();

    if (!thread || body === '' || this.sending()) {
      return;
    }

    this.sending.set(true);

    this.messages.send(thread.id, body).subscribe({
      next: (message) => {
        this.sending.set(false);
        this.draft.set('');
        this.scrollPending = true;

        this.thread.update((current) =>
          current ? { ...current, items: [...current.items, message] } : current,
        );

        // Keep the list preview honest without a second round trip.
        this.conversations.update((items) =>
          items.map((item) =>
            item.id === thread.id
              ? {
                  ...item,
                  last_message: {
                    body: message.body,
                    sent_by_me: true,
                    created_at: message.created_at,
                  },
                }
              : item,
          ),
        );
      },
      error: (response: HttpErrorResponse) => {
        this.sending.set(false);
        this.error.set(describeError(response, 'Could not send that message.'));
      },
    });
  }

  /** Enter sends; Shift+Enter starts a new line. */
  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.send();
    }
  }

  protected time(iso: string | null): string {
    if (!iso) {
      return '';
    }

    const date = new Date(iso);
    const today = new Date().toDateString() === date.toDateString();

    return today
      ? date.toLocaleTimeString('en-AU', { hour: 'numeric', minute: '2-digit' })
      : date.toLocaleDateString('en-AU', { day: 'numeric', month: 'short' });
  }
}
