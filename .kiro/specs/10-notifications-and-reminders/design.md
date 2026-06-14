# Spec 10 — Design

## POS App

### Notification Bell (Kitchen Layout Header)

```
┌─────────────────────────────────────────────────┐
│  [≡] Sunbites POS          [🔔 3]  [Branch ▾]   │
└─────────────────────────────────────────────────┘
```

- Bell icon with red badge showing count of unsent eligible parents
- Badge hidden when count is 0 or reminder window is not open
- Clicks navigate to `/reminders`
- Visible only to admin | manager | supervisor

---

### Top-Level Nav Item: Reminders

Added between "Students" and "Reports" group in `kitchen-layout.tsx`:

```
Dashboard
POS
Enrollment
Students
Reminders  ← new (Bell icon)
──────────────────
Reports
  Sales
  ...
──────────────────
References
  ...
```

Visible to admin | manager | supervisor. Hidden for cashier and regular staff.

---

### Reminders Page — `/reminders`

```
┌─────────────────────────────────────────────────────────────────┐
│ Reminders                                              [Send (2)] │
│ August 2026 · 14 days before Aug 1                              │
├────┬──────────────────────────┬─────────────────────┬───────────┤
│ ☐  │ Parent Name              │ Students            │ Status    │
├────┼──────────────────────────┼─────────────────────┼───────────┤
│ ☑  │ Maria Santos             │ Juan Santos (Gr.3)  │ Not sent  │
│ ☑  │ Pedro Reyes              │ Ana Reyes (Gr.1)    │ Not sent  │
│ ☐  │ Clara Lim                │ Leo Lim (Gr.2)      │ Sent ✓    │
└────┴──────────────────────────┴─────────────────────┴───────────┘
       [☐ Select All]
```

- Only parents with subscription students in the active branch are listed
- "Sent ✓" rows are shown grayed out with a checkmark; checkbox is disabled for them
- "Not sent" rows have enabled checkboxes
- "Select all" selects only the unsent (enabled) rows
- Send button label shows count of selected: `Send (2)`
- Send button disabled when no rows selected

#### Duplicate Warning Dialog

Shown when any selected parent was already sent a reminder this month:

```
┌──────────────────────────────────────────────────┐
│ Some parents already notified                    │
│                                                  │
│ These parents were already sent a reminder       │
│ for August:                                      │
│                                                  │
│  • Clara Lim — sent Jun 20 at 3:45 PM            │
│                                                  │
│ Send again to them as well?                      │
│                                                  │
│              [Cancel]  [Send to all anyway]      │
└──────────────────────────────────────────────────┘
```

---

### Reminder Detail Page — `/reminders/[parentId]`

```
┌─────────────────────────────────────────────────┐
│ ← Back to Reminders                             │
│                                                 │
│ 👤 Maria Santos                                 │
│    maria@email.com · 09171234567                │
│    123 Rizal St, Iloilo City                    │
├─────────────────────────────────────────────────┤
│ Linked Students                                 │
│                                                 │
│  Juan Santos · Grade 3 · Subscription           │
│  ─────────────────────────────────────────      │
│  Payment History                                │
│                                                 │
│  Month       Amount     Status   Paid Date      │
│  June        ₱2,970     Paid     Jun 5, 2026    │
│  July        ₱2,970     Paid     Jun 30, 2026   │
│  August      ₱2,430     Unpaid   —              │
│  ...                                            │
└─────────────────────────────────────────────────┘
```

---

## Parent Portal

### Notification Bell (Portal Header)

```
┌────────────────────────────────────────────┐
│  Sunbites Portal         [🔔 2]   [Menu]  │
└────────────────────────────────────────────┘
```

- Badge count = unread notifications
- Badge hidden when count is 0
- Clicks navigate to `/notifications`

---

### Notifications Page — `/notifications`

```
┌──────────────────────────────────────────────────────────┐
│ Notifications                                            │
│                              [Mark all read] [Clear all] │
├──────────────────────────────────────────────────────────┤
│ ● August Payment Reminder          Jun 18 · 2:30 PM  [✕] │
│   Juan's August subscription (₱2,430) is due before      │
│   August 1. Please settle your payment.                  │
├──────────────────────────────────────────────────────────┤
│   July Payment Reminder            Jun 5 · 10:00 AM  [✕] │
│   Juan's July subscription (₱2,970) was paid. ✓         │
└──────────────────────────────────────────────────────────┘
│ Empty state: No notifications yet.                        │
└──────────────────────────────────────────────────────────┘
```

- Unread rows have a filled dot (●) on the left and slightly highlighted background
- Read rows have no dot, normal background
- Clicking a row marks it as read (PATCH `/notifications/{id}/read`)
- [✕] deletes the individual notification
- "Mark all read" — POST `/notifications/mark-all-read`
- "Clear all" — DELETE `/notifications` with a confirmation dialog

---

### Student Payment History — Portal Students Page

Added as a collapsible section on each student card (subscription students only):

```
┌──────────────────────────────────────────────────┐
│ Juan Santos · Grade 3 · Subscription             │
│ Wallet: ₱500.00                                  │
│                                                  │
│ [▾ Payment History]                              │
│                                                  │
│  Month     Amount    Status   Paid Date          │
│  June      ₱2,970    Paid     Jun 5, 2026        │
│  July      ₱2,970    Unpaid   —                  │
│  August    ₱2,430    Unpaid   —                  │
│  ...                                             │
└──────────────────────────────────────────────────┘
```

Non-subscription students: payment history section not shown.

---

---

## Real-Time Architecture (Laravel Reverb)

### Flow

```
Staff sends reminder
        │
        ▼
ReminderController::send()
  ├── Creates ParentPaymentReminder record
  └── Dispatches PaymentReminderNotification (queued)
              │
              ├── toDatabase() → writes to `notifications` table
              └── broadcastOn() → Reverb broadcasts on PrivateChannel('parents.{id}')
                                          │
                                          ▼
                              Next.js Portal (Echo client)
                              echo.private('parents.{id}')
                                .listen('PaymentReminderNotification', () => {
                                    queryClient.invalidateQueries(['unread-count'])
                                })
                              → Bell badge updates in real time
```

### Backend — Broadcasting

**`App\Notifications\PaymentReminderNotification`** implements `ShouldBroadcast`:

```php
public function via(): array
{
    return ['database', 'broadcast'];
}

public function broadcastOn(): array
{
    return [new PrivateChannel("parents.{$this->parent->id}")];
}

public function broadcastAs(): string
{
    return 'PaymentReminderNotification';
}
```

**`routes/channels.php`** — private channel auth:

```php
Broadcast::channel('parents.{parentId}', function (ParentUser $user, int $parentId) {
    return $user->id === $parentId;
});
```

**`routes/portal-api.php`** — add at the top of the authenticated group:

```php
Broadcast::routes(['middleware' => ['auth:parents', 'ability:parent']]);
```

### Frontend — EchoProvider

`components/providers/echo-provider.tsx` (Client Component):

```typescript
"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { createContext, useContext, useEffect, useRef } from "react";
import { useAuthStore } from "@/lib/store/auth";

window.Pusher = Pusher;

const EchoContext = createContext<Echo | null>(null);

export function EchoProvider({ children }: { children: React.ReactNode }) {
  const echoRef = useRef<Echo | null>(null);
  const token = useAuthStore((s) => s.token);

  useEffect(() => {
    if (!token) return;

    echoRef.current = new Echo({
      broadcaster: "reverb",
      key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
      wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
      wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 443),
      forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === "https",
      authEndpoint: `${process.env.NEXT_PUBLIC_API_URL}/api/v1/portal/broadcasting/auth`,
      auth: { headers: { Authorization: `Bearer ${token}` } },
    });

    return () => {
      echoRef.current?.disconnect();
    };
  }, [token]);

  return (
    <EchoContext.Provider value={echoRef.current}>
      {children}
    </EchoContext.Provider>
  );
}

export function useEcho() {
  return useContext(EchoContext);
}
```

Wrap in `app/(portal)/layout.tsx`:

```typescript
export default function PortalLayout({ children }) {
  return (
    <EchoProvider>
      <PortalLayoutShell>{children}</PortalLayoutShell>
    </EchoProvider>
  );
}
```

### NotificationBell — Real-Time Subscription

```typescript
"use client";

export function NotificationBell({ parentId }: { parentId: number }) {
  const echo = useEcho();
  const queryClient = useQueryClient();
  const { data } = useQuery({ queryKey: ["unread-count"], queryFn: notificationApi.unreadCount });

  useEffect(() => {
    if (!echo) return;
    const channel = echo
      .private(`parents.${parentId}`)
      .listen("PaymentReminderNotification", () => {
        queryClient.invalidateQueries({ queryKey: ["unread-count"] });
      });
    return () => channel.stopListening("PaymentReminderNotification");
  }, [echo, parentId, queryClient]);

  // ... render bell + badge
}
```

No `refetchInterval` needed — the TanStack Query cache is invalidated on each broadcast event.

### Laravel Cloud Deployment

Add to `laravel.cloud`:

```yaml
workers:
  - type: web
  - type: queue
    queue: default
  - type: reverb
    replicas: 1
```

Cloud sets `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` automatically after provisioning.

---

## Notes

- All reminder operations are branch-scoped — parents with students in Iloilo branch are not visible to staff logged into Bacolod branch.
- `payment_reminder_days` is read via `SystemConfiguration::getValue('payment_reminder_days', 14)`.
- The "upcoming month" is determined server-side: find the next school month whose 1st calendar date is ≤ `payment_reminder_days` days away, and > 0 days away (i.e., the month hasn't started yet).
- If multiple school months are simultaneously within the window (unlikely but possible), the soonest one is used.
- School months with no subscription students in the branch produce a bell count of 0 even if the window is open.

### School Year Calendar Logic (for `bellCount()`)

School months span two calendar years (June–December in year Y, January–March in year Y+1). To map a school month key to its calendar date:

```php
// Determine the start year of the current school year
$now = now();
$schoolYearStart = $now->month >= 6 ? $now->year : $now->year - 1;

// Map each month key to its calendar year
$monthCalendarYear = [
    'june' => $schoolYearStart,     'july'      => $schoolYearStart,
    'august' => $schoolYearStart,   'september' => $schoolYearStart,
    'october' => $schoolYearStart,  'november'  => $schoolYearStart,
    'december' => $schoolYearStart, 'january'   => $schoolYearStart + 1,
    'february' => $schoolYearStart + 1, 'march' => $schoolYearStart + 1,
];

// Find the upcoming month within the reminder window
foreach (config('sunbites.school_months') as $key => $config) {
    $monthNumber = Carbon::parse("1 {$key}")->month;
    $firstOfMonth = Carbon::create($monthCalendarYear[$key], $monthNumber, 1);
    $daysUntil = $now->diffInDays($firstOfMonth, false); // negative = already started
    if ($daysUntil > 0 && $daysUntil <= $paymentReminderDays) {
        // This is the upcoming month — compute bell count for it
        $upcomingMonth = $key;
        $upcomingYear = $monthCalendarYear[$key];
        break;
    }
}
```

The `school_year` stored in `parent_payment_reminders` is the calendar year when the month falls (e.g., August 2026 → `school_year = 2026`, January 2027 → `school_year = 2027`).
