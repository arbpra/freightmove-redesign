# Workflow and Process Diagrams

Flows reflect the legacy behaviour that must be preserved (see
`docs/10-domain-rules.md`) plus the lifecycle V2 adds. Steps the legacy app did
not have are marked **[new]**; rules carried over are marked **[legacy]**.

## 1. Posting a load

```mermaid
flowchart TD
    A[Shipper completes the load form] --> B{Required fields present?}
    B -->|No| A
    B -->|Yes| C[Resolve pickup and dropoff suburbs]
    C --> D{Route already cached?}
    D -->|Yes| E[Reuse cached distance, increment lookup_count]
    D -->|No| F[Queue a Distance Matrix lookup, cache the result]
    E --> G[Persist load with categories and truck types]
    F --> G
    G --> H{Save as draft or publish?}
    H -->|Draft| I[status = draft, not visible to carriers]
    H -->|Publish| J[status = published]
    J --> K[Queue notifications to carriers on this lane]
```

- **[legacy]** The distance cache. Never call the API for a pair already seen.
- **[legacy]** Many categories and many truck types per load.
- **[new]** Draft state. The legacy app inserted immediately with no draft.
- **[new]** The lookup is queued, so posting never blocks on a third-party call.

## 2. Browsing the load board (carrier)

```mermaid
flowchart TD
    A[Carrier opens the board] --> B{Active subscription?}
    B -->|No| C[Show plans — browsing allowed, quoting blocked]
    B -->|Yes| D[Apply filters: pickup state, dropoff state, availability]
    D --> E[Restrict to the recency window]
    E --> F[Return JSON, ranked by relist then post date]
```

- **[legacy]** Subscription gates the paid capability (**G4**).
- **[legacy]** Filters combine freely — one query with `when()` clauses, never
  a branch per combination.
- **[legacy]** Recency window, previously a hardcoded 7 days; now configurable
  (**G5**).
- **[new]** JSON, not an HTML table fragment assembled in the controller.

## 3. Quoting

```mermaid
sequenceDiagram
    participant C as Carrier
    participant B as Backend
    participant S as Shipper

    C->>B: Submit price and notes
    B->>B: Check active subscription
    B->>B: Reject if this carrier already quoted this load
    B->>B: Store quote (status = pending)
    B->>S: Queue "new quote" notification
    S->>B: Accept a quote
    B->>B: Record acceptance, move job to accepted
    B->>C: Notify the winning carrier
    B->>C: Notify the carriers not selected
```

- **[legacy]** One quote per carrier per load — in V2 a unique index, not a
  code-only check.
- **[new]** Everything from "Accept a quote" onward. The legacy schema recorded
  no accept or decline state, which is why only 4 of 143 legacy quotes could be
  reconstructed with an outcome.

## 4. Relisting

```mermaid
flowchart TD
    A[Load ages past the board window] --> B[Shipper relists]
    B --> C[Set relisted_at, keep updated_at for real edits]
    C --> D[Load reappears on the board]
```

**[legacy]** behaviour, **[new]** mechanism: the old app bumped `date_updated`,
making an edit indistinguishable from a relist (**G6**).

## 5. Carrier subscription

```mermaid
flowchart TD
    A[Carrier chooses a plan] --> B[Create order with the payment provider]
    B --> C{Payment completed?}
    C -->|No| D[Return to plans, nothing recorded]
    C -->|Yes| E[Record payment]
    E --> F[Create or extend the subscription period]
    F --> G[Quoting unlocked until ends_on]
```

- **[legacy]** Plans, periods and payment history — all three migrated.
- **[new]** Plans resolve by slug, not the hardcoded record id the legacy
  PayPal controller used, so recreating a plan cannot break checkout.

## 6. Notifications

```mermaid
flowchart TD
    A[Event occurs] --> B[Write a notification record]
    B --> C[Queue email]
    C --> D[User sees it in the notification centre]
```

**[new]** One path. The legacy app sent mail two inconsistent ways — Laravel
Mailables in places, a raw Guzzle POST to SendGrid with HTML built by string
concatenation in others.

## 7. Admin approval

```mermaid
flowchart TD
    A[Carrier submits documents] --> B[Admin reviews]
    B --> C{Approved?}
    C -->|Yes| D[verification_status = verified]
    C -->|No| E[Rejected with a note, resubmission allowed]
```

**[new]** The legacy app had no document verification workflow.
