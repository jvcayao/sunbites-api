# Design 09 — System Configuration

---

## Screen: System Settings

**Route:** `pos.sunbites.com.ph/references/system-settings`
**Nav item:** ⚙️ System Settings (References group, admin-only)
**Layout:** `KitchenLayout`
**Access:** Admin only — redirect to `/dashboard` if not admin

```
┌──────────────────────────────────────────────────────────────┐
│  ⚙️  System Settings                                         │
│  Configure system-wide business rules and rate constants.    │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Label                    Value       Actions        │   │
│  │  ─────────────────────────────────────────────────  │   │
│  │  Daily Meal Rate (₱)      ₱135.00    [Edit]         │   │
│  │  Daily rate used to compute monthly subscription     │   │
│  │  amounts when no override is set.                    │   │
│  │  ─────────────────────────────────────────────────  │   │
│  │  Credit Limit (₱)         ₱300.00    [Edit]         │   │
│  │  Maximum outstanding credit a student may carry.    │   │
│  │  ─────────────────────────────────────────────────  │   │
│  │  Loyalty Point Threshold  ₱1,000.00  [Edit]         │   │
│  │  (₱) Amount spent to earn one loyalty point.        │   │
│  │  ─────────────────────────────────────────────────  │   │
│  │  Payment Reminder Days    14         [Edit]         │   │
│  │  Days before due date to send payment reminders.    │   │
│  │  ─────────────────────────────────────────────────  │   │
│  │  Pre-Registration Expiry  30         [Edit]         │   │
│  │  Days before an unprocessed pre-reg entry expires.  │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                              │
│  ⓘ Changes take effect immediately across the system.       │
└──────────────────────────────────────────────────────────────┘
```

**Edit Dialog:**

```
┌─── Edit: Daily Meal Rate (₱) ────────────────────────────┐
│                                                           │
│  Daily rate used to compute monthly subscription          │
│  amounts when no override is set.                         │
│                                                           │
│  New Value *                                              │
│  [135.00                                               ]  │
│                                                           │
│               [Cancel]    [Save Changes]                  │
└───────────────────────────────────────────────────────────┘
```

**Component Notes:**
- Table uses `divide-y divide-border` rows
- Value displayed formatted: `₱` prefix for decimal types, plain number for integer
- Description shown as `text-xs text-muted-foreground` below the label
- Edit dialog: number input for integer/decimal types, text input for string type
- Save button: disabled while mutation is pending
- On success: close dialog, show inline "Saved" indicator (green text, fades after 2s)
- Admin-only redirect guard via `useEffect` + `useRouter` (same pattern as subscription-config page)

---

## Backend: SystemConfiguration Model

```php
class SystemConfiguration extends Model
{
    protected $fillable = ['key', 'value', 'type', 'label', 'description'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_config.{$key}", function () use ($key, $default) {
            $record = static::where('key', $key)->first();
            if (!$record) return $default;
            return match ($record->type) {
                'integer' => (int) $record->value,
                'decimal' => (float) $record->value,
                default   => $record->value,
            };
        });
    }

    protected static function booted(): void
    {
        static::updated(function (self $config) {
            Cache::forget("system_config.{$config->key}");
        });
    }
}
```

---

## Backend: SystemConfigurationController

```
GET  /api/v1/system-configurations       → index()  → returns all rows
PUT  /api/v1/system-configurations/{key} → update() → validates value, saves, busts cache
```

Validation in `update()`:
- `integer` type: `['required', 'integer', 'min:0']`
- `decimal` type: `['required', 'numeric', 'min:0']`
- `string` type: `['required', 'string', 'max:255']`

Returns the updated record on success (200).

---

## API Response Shape

`GET /api/v1/system-configurations`:
```json
[
  {
    "key": "daily_meal_rate",
    "value": "135",
    "type": "decimal",
    "label": "Daily Meal Rate (₱)",
    "description": "Daily rate used to compute monthly subscription amounts when no override is set."
  },
  ...
]
```

`PUT /api/v1/system-configurations/{key}`:
- Body: `{ "value": "150" }`
- Returns: the updated config object (same shape as above)
