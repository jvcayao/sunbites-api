# Parent Account Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add enable/disable access, soft-delete, and restore-with-forced-password-reset to parent accounts, managed from the POS and enforced at portal login.

**Architecture:** Four action classes (`DisableParentAction`, `EnableParentAction`, `SoftDeleteParentAction`, `RestoreParentAction`) handle all state transitions. Controllers stay thin. A `disabled_at` timestamp blocks access at login; `deleted_at` soft-deletes via Laravel `SoftDeletes`. The POS detail page exposes all four actions; the list page shows state badges and a show-deleted toggle.

**Tech Stack:** Laravel 13, Sanctum, Spatie Permissions, PHPUnit 12 (backend) · Next.js App Router, TanStack Query, MSW 2, Jest 30, React Testing Library 16 (frontend)

---

## File Map

### Backend — `~/sunbites-api`

| File | Action |
|---|---|
| `database/migrations/XXXX_add_disabled_at_to_parents_table.php` | Create |
| `database/migrations/XXXX_add_soft_deletes_to_parents_table.php` | Create |
| `database/factories/ParentUserFactory.php` | Create |
| `app/Models/ParentUser.php` | Modify — SoftDeletes, disabled_at, helpers |
| `app/Actions/Parents/DisableParentAction.php` | Create |
| `app/Actions/Parents/EnableParentAction.php` | Create |
| `app/Actions/Parents/SoftDeleteParentAction.php` | Create |
| `app/Actions/Parents/RestoreParentAction.php` | Create |
| `app/Http/Controllers/Kitchen/ParentController.php` | Modify — 4 new methods, updated index/show |
| `app/Http/Controllers/Portal/AuthController.php` | Modify — add isDisabled() check |
| `routes/kitchen-api.php` | Modify — 4 new routes + withTrashed on show |
| `tests/Feature/Kitchen/ParentAccountManagementTest.php` | Create |

### Frontend — `~/sunbites-pos`

| File | Action |
|---|---|
| `types/parent.ts` | Modify — add `is_disabled`, `deleted_at` |
| `lib/api/parents.ts` | Modify — add 4 new calls |
| `app/(kitchen)/references/parents/page.tsx` | Modify — state badges, show-deleted toggle |
| `app/(kitchen)/references/parents/[id]/page.tsx` | Modify — action buttons |
| `__tests__/mocks/handlers.ts` | Modify — add mutation handlers |
| `__tests__/parents/parent-account-management.test.tsx` | Create |

---

## Task 1: Database migrations

**Files:**
- Create: `database/migrations/XXXX_add_disabled_at_to_parents_table.php`
- Create: `database/migrations/XXXX_add_soft_deletes_to_parents_table.php`

- [ ] **Step 1: Generate the two migrations**

```bash
cd ~/sunbites-api
vendor/bin/sail artisan make:migration add_disabled_at_to_parents_table --table=parents
vendor/bin/sail artisan make:migration add_soft_deletes_to_parents_table --table=parents
```

- [ ] **Step 2: Fill in the disabled_at migration**

Open the generated `add_disabled_at_to_parents_table` file and replace the `up` and `down` methods:

```php
public function up(): void
{
    Schema::table('parents', function (Blueprint $table) {
        $table->timestamp('disabled_at')->nullable()->after('remember_token');
    });
}

public function down(): void
{
    Schema::table('parents', function (Blueprint $table) {
        $table->dropColumn('disabled_at');
    });
}
```

- [ ] **Step 3: Fill in the soft deletes migration**

Open the generated `add_soft_deletes_to_parents_table` file and replace:

```php
public function up(): void
{
    Schema::table('parents', function (Blueprint $table) {
        $table->softDeletes();
    });
}

public function down(): void
{
    Schema::table('parents', function (Blueprint $table) {
        $table->dropSoftDeletes();
    });
}
```

- [ ] **Step 4: Run migrations**

```bash
vendor/bin/sail artisan migrate
```

Expected: both migrations run without errors.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/
git commit -m "feat: add disabled_at and soft_deletes to parents table"
```

---

## Task 2: Update ParentUser model + create ParentUserFactory

**Files:**
- Modify: `app/Models/ParentUser.php`
- Create: `database/factories/ParentUserFactory.php`

- [ ] **Step 1: Update ParentUser.php**

Replace the entire file content with:

```php
<?php

namespace App\Models;

use Database\Factories\ParentUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class ParentUser extends Authenticatable
{
    /** @use HasFactory<ParentUserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'parents';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'address',
        'profile_photo_path',
        'email_verified_at',
        'disabled_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActivated(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isAccessible(): bool
    {
        return ! $this->isDisabled() && $this->isActivated();
    }

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => "{$this->first_name} {$this->last_name}",
        );
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->profile_photo_path
                ? Storage::url($this->profile_photo_path)
                : null,
        );
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id')
            ->withPivot(['wallet_alert_threshold', 'linked_at', 'linked_by']);
    }

    public function receivesBroadcastNotificationsOn(): string
    {
        return "parents.{$this->id}";
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'parent_id');
    }
}
```

- [ ] **Step 2: Create ParentUserFactory**

```bash
vendor/bin/sail artisan make:factory ParentUserFactory --model=ParentUser
```

- [ ] **Step 3: Fill in the factory**

Replace the generated file with:

```php
<?php

namespace Database\Factories;

use App\Models\ParentUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<ParentUser>
 */
class ParentUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Password1'),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'profile_photo_path' => null,
            'email_verified_at' => now(),
            'disabled_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function unactivated(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'email_verified_at' => now(),
            'disabled_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/Models/ParentUser.php database/factories/ParentUserFactory.php
git commit -m "feat: add SoftDeletes, disabled_at, and factory to ParentUser"
```

---

## Task 3: DisableParentAction — TDD

**Files:**
- Create: `app/Actions/Parents/DisableParentAction.php`
- Create: `tests/Feature/Kitchen/ParentAccountManagementTest.php`
- Modify: `app/Http/Controllers/Kitchen/ParentController.php`
- Modify: `routes/kitchen-api.php`

- [ ] **Step 1: Create the test file with the disable test**

```bash
vendor/bin/sail artisan make:test --phpunit tests/Feature/Kitchen/ParentAccountManagementTest
```

Replace the generated file with:

```php
<?php

namespace Tests\Feature\Kitchen;

use App\Mail\ParentWelcomeMail;
use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentAccountManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function asUserWithRole(string $role): static
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    // -------------------------------------------------------------------------
    // disable
    // -------------------------------------------------------------------------

    public function test_admin_can_disable_an_active_parent(): void
    {
        $parent = ParentUser::factory()->create();
        $parent->createToken('portal-token', ['parent']);

        $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/disable");

        $response->assertOk();
        $response->assertJson(['message' => 'Parent access disabled.']);
        $this->assertNotNull($parent->fresh()->disabled_at);
        $this->assertCount(0, $parent->tokens()->get());
    }
}
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_disable_an_active_parent
```

Expected: FAIL — route not found (404).

- [ ] **Step 3: Create the action class**

```bash
vendor/bin/sail artisan make:class app/Actions/Parents/DisableParentAction
```

Replace the generated file with:

```php
<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class DisableParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->tokens()->delete();
        $parent->update(['disabled_at' => now()]);
    }
}
```

- [ ] **Step 4: Add the controller method**

Open `app/Http/Controllers/Kitchen/ParentController.php` and add the import and method:

Add to the imports:
```php
use App\Actions\Parents\DisableParentAction;
```

Add the method after `resendActivation`:
```php
public function disable(ParentUser $parent): JsonResponse
{
    (new DisableParentAction)->execute($parent);

    return response()->json(['message' => 'Parent access disabled.']);
}
```

- [ ] **Step 5: Add the route**

Open `routes/kitchen-api.php`. Inside the `role:admin|manager` group where parent routes live (around line 166), add:

```php
Route::post('/references/parents/{parent}/disable', [ParentController::class, 'disable']);
```

- [ ] **Step 6: Run the test — expect pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_disable_an_active_parent
```

Expected: PASS.

- [ ] **Step 7: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Actions/Parents/DisableParentAction.php app/Http/Controllers/Kitchen/ParentController.php routes/kitchen-api.php tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "feat: add DisableParentAction and disable route"
```

---

## Task 4: EnableParentAction — TDD

**Files:**
- Create: `app/Actions/Parents/EnableParentAction.php`
- Modify: `app/Http/Controllers/Kitchen/ParentController.php`
- Modify: `routes/kitchen-api.php`
- Modify: `tests/Feature/Kitchen/ParentAccountManagementTest.php`

- [ ] **Step 1: Add the enable test**

Append to `ParentAccountManagementTest.php`, inside the class:

```php
// -------------------------------------------------------------------------
// enable
// -------------------------------------------------------------------------

public function test_admin_can_enable_a_disabled_parent(): void
{
    Mail::fake();

    $parent = ParentUser::factory()->disabled()->create();
    $parent->createToken('old-token', ['parent']);

    $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/enable");

    $response->assertOk();
    $response->assertJson(['message' => 'Parent access enabled. Activation email sent.']);
    $fresh = $parent->fresh();
    $this->assertNull($fresh->disabled_at);
    $this->assertNull($fresh->email_verified_at);
    $this->assertCount(0, $parent->tokens()->get());
    Mail::assertQueued(ParentWelcomeMail::class, fn ($mail) => $mail->hasTo($parent->email));
}
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_enable_a_disabled_parent
```

Expected: FAIL — route not found (404).

- [ ] **Step 3: Create the action class**

```bash
vendor/bin/sail artisan make:class app/Actions/Parents/EnableParentAction
```

Replace with:

```php
<?php

namespace App\Actions\Parents;

use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class EnableParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->tokens()->delete();
        $parent->forceFill([
            'disabled_at' => null,
            'email_verified_at' => null,
        ])->save();

        $token = Password::broker('parents')->createToken($parent);
        Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));
    }
}
```

- [ ] **Step 4: Add the controller method**

Add to `ParentController.php` imports:
```php
use App\Actions\Parents\EnableParentAction;
```

Add the method:
```php
public function enable(ParentUser $parent): JsonResponse
{
    (new EnableParentAction)->execute($parent);

    return response()->json(['message' => 'Parent access enabled. Activation email sent.']);
}
```

- [ ] **Step 5: Add the route**

In `routes/kitchen-api.php`, inside the `role:admin|manager` parent group, add:

```php
Route::post('/references/parents/{parent}/enable', [ParentController::class, 'enable']);
```

- [ ] **Step 6: Run the test — expect pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_enable_a_disabled_parent
```

Expected: PASS.

- [ ] **Step 7: Run pint**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Actions/Parents/EnableParentAction.php app/Http/Controllers/Kitchen/ParentController.php routes/kitchen-api.php tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "feat: add EnableParentAction and enable route"
```

---

## Task 5: SoftDeleteParentAction — TDD

**Files:**
- Create: `app/Actions/Parents/SoftDeleteParentAction.php`
- Modify: `app/Http/Controllers/Kitchen/ParentController.php`
- Modify: `routes/kitchen-api.php`
- Modify: `tests/Feature/Kitchen/ParentAccountManagementTest.php`

- [ ] **Step 1: Add the soft-delete test**

Append to `ParentAccountManagementTest.php`:

```php
// -------------------------------------------------------------------------
// destroy (soft delete)
// -------------------------------------------------------------------------

public function test_admin_can_soft_delete_a_parent(): void
{
    $parent = ParentUser::factory()->create();
    $parent->createToken('portal-token', ['parent']);

    $response = $this->asAdmin()->deleteJson("/api/v1/references/parents/{$parent->id}");

    $response->assertOk();
    $response->assertJson(['message' => 'Parent account deleted.']);
    $this->assertNotNull(ParentUser::withTrashed()->find($parent->id)->deleted_at);
    $this->assertCount(0, $parent->tokens()->get());
}
```

- [ ] **Step 2: Run the test — expect failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_soft_delete_a_parent
```

Expected: FAIL — route not found.

- [ ] **Step 3: Create the action class**

```bash
vendor/bin/sail artisan make:class app/Actions/Parents/SoftDeleteParentAction
```

Replace with:

```php
<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class SoftDeleteParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->tokens()->delete();
        $parent->delete();
    }
}
```

- [ ] **Step 4: Add the controller method**

Add import:
```php
use App\Actions\Parents\SoftDeleteParentAction;
```

Add method:
```php
public function destroy(ParentUser $parent): JsonResponse
{
    (new SoftDeleteParentAction)->execute($parent);

    return response()->json(['message' => 'Parent account deleted.']);
}
```

- [ ] **Step 5: Add the route**

Inside the `role:admin|manager` parent group:

```php
Route::delete('/references/parents/{parent}', [ParentController::class, 'destroy']);
```

- [ ] **Step 6: Run the test — expect pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_soft_delete_a_parent
```

Expected: PASS.

- [ ] **Step 7: Run pint and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Actions/Parents/SoftDeleteParentAction.php app/Http/Controllers/Kitchen/ParentController.php routes/kitchen-api.php tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "feat: add SoftDeleteParentAction and destroy route"
```

---

## Task 6: RestoreParentAction — TDD

**Files:**
- Create: `app/Actions/Parents/RestoreParentAction.php`
- Modify: `app/Http/Controllers/Kitchen/ParentController.php`
- Modify: `routes/kitchen-api.php`
- Modify: `tests/Feature/Kitchen/ParentAccountManagementTest.php`

- [ ] **Step 1: Add the restore tests**

Append to `ParentAccountManagementTest.php`:

```php
// -------------------------------------------------------------------------
// restore
// -------------------------------------------------------------------------

public function test_admin_can_restore_a_soft_deleted_parent(): void
{
    Mail::fake();

    $parent = ParentUser::factory()->create();
    $parent->delete();

    $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/restore");

    $response->assertOk();
    $response->assertJson(['message' => 'Parent account restored. Activation email sent.']);
    $fresh = ParentUser::withTrashed()->find($parent->id);
    $this->assertNull($fresh->deleted_at);
    $this->assertNull($fresh->disabled_at);
    $this->assertNull($fresh->email_verified_at);
    Mail::assertQueued(ParentWelcomeMail::class, fn ($mail) => $mail->hasTo($parent->email));
}

public function test_restoring_a_non_deleted_parent_returns_not_found(): void
{
    $parent = ParentUser::factory()->create();

    $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/restore");

    $response->assertNotFound();
}
```

- [ ] **Step 2: Run the tests — expect failure**

```bash
vendor/bin/sail artisan test --compact --filter=ParentAccountManagementTest::test_admin_can_restore_a_soft_deleted_parent
```

Expected: FAIL — route not found.

- [ ] **Step 3: Create the action class**

```bash
vendor/bin/sail artisan make:class app/Actions/Parents/RestoreParentAction
```

Replace with:

```php
<?php

namespace App\Actions\Parents;

use App\Models\ParentUser;

class RestoreParentAction
{
    public function execute(ParentUser $parent): void
    {
        $parent->restore();
        $parent->forceFill(['disabled_at' => null])->save();
        (new EnableParentAction)->execute($parent);
    }
}
```

- [ ] **Step 4: Add the controller method**

Add imports:
```php
use App\Actions\Parents\RestoreParentAction;
```

Add method:
```php
public function restore(ParentUser $parent): JsonResponse
{
    if (! $parent->trashed()) {
        abort(404);
    }

    (new RestoreParentAction)->execute($parent);

    return response()->json(['message' => 'Parent account restored. Activation email sent.']);
}
```

- [ ] **Step 5: Add the route with withTrashed**

Inside the `role:admin|manager` parent group:

```php
Route::post('/references/parents/{parent}/restore', [ParentController::class, 'restore'])->withTrashed();
```

- [ ] **Step 6: Run both restore tests — expect pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_admin_can_restore_a_soft_deleted_parent
vendor/bin/sail artisan test --compact --filter=test_restoring_a_non_deleted_parent_returns_not_found
```

Expected: both PASS.

- [ ] **Step 7: Run pint and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Actions/Parents/RestoreParentAction.php app/Http/Controllers/Kitchen/ParentController.php routes/kitchen-api.php tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "feat: add RestoreParentAction and restore route"
```

---

## Task 7: Portal login disable check — TDD

**Files:**
- Modify: `app/Http/Controllers/Portal/AuthController.php`
- Modify: `tests/Feature/Kitchen/ParentAccountManagementTest.php`

- [ ] **Step 1: Add portal login tests**

Append to `ParentAccountManagementTest.php`:

```php
// -------------------------------------------------------------------------
// portal login — disable and soft-delete enforcement
// -------------------------------------------------------------------------

public function test_disabled_parent_is_rejected_at_login_with_account_disabled_error(): void
{
    $parent = ParentUser::factory()->disabled()->create([
        'password' => bcrypt('Password1'),
    ]);

    $response = $this->postJson('/api/v1/portal/auth/login', [
        'email' => $parent->email,
        'password' => 'Password1',
    ]);

    $response->assertUnauthorized();
    $response->assertJsonPath('error', 'account_disabled');
}

public function test_soft_deleted_parent_login_returns_invalid_credentials(): void
{
    $parent = ParentUser::factory()->create([
        'password' => bcrypt('Password1'),
    ]);
    $parent->delete();

    $response = $this->postJson('/api/v1/portal/auth/login', [
        'email' => $parent->email,
        'password' => 'Password1',
    ]);

    $response->assertUnprocessable();
}
```

- [ ] **Step 2: Run the tests — expect failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_disabled_parent_is_rejected_at_login_with_account_disabled_error
```

Expected: FAIL — disabled parent currently logs in successfully.

- [ ] **Step 3: Update AuthController::login**

Open `app/Http/Controllers/Portal/AuthController.php`. In the `login` method, add the disable check between the `isActivated()` check and the password check:

```php
public function login(Request $request): JsonResponse
{
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $parent = ParentUser::where('email', $request->email)->first();

    if (! $parent) {
        return response()->json(['message' => 'Invalid credentials.'], 422);
    }

    if (! $parent->isActivated()) {
        return response()->json([
            'message' => 'Account not yet activated.',
            'error' => 'account_not_activated',
        ], 401);
    }

    if ($parent->isDisabled()) {
        return response()->json([
            'message' => 'Account access has been disabled.',
            'error' => 'account_disabled',
        ], 401);
    }

    if (! Hash::check($request->password, $parent->password ?? '')) {
        return response()->json(['message' => 'Invalid credentials.'], 422);
    }

    $token = $parent->createToken('portal-token', ['parent'])->plainTextToken;

    return response()->json([
        'token' => $token,
        'parent' => [
            'id' => $parent->id,
            'first_name' => $parent->first_name,
            'last_name' => $parent->last_name,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'address' => $parent->address,
            'profile_photo_url' => $parent->profile_photo_url,
        ],
    ]);
}
```

- [ ] **Step 4: Run both portal tests — expect pass**

```bash
vendor/bin/sail artisan test --compact --filter=test_disabled_parent_is_rejected_at_login_with_account_disabled_error
vendor/bin/sail artisan test --compact --filter=test_soft_deleted_parent_login_returns_invalid_credentials
```

Expected: both PASS.

- [ ] **Step 5: Run pint and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Portal/AuthController.php tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "feat: block disabled parents at portal login"
```

---

## Task 8: Update index/show responses — TDD

**Files:**
- Modify: `app/Http/Controllers/Kitchen/ParentController.php`
- Modify: `routes/kitchen-api.php`
- Modify: `tests/Feature/Kitchen/ParentAccountManagementTest.php`

- [ ] **Step 1: Add index/show response tests**

Append to `ParentAccountManagementTest.php`:

```php
// -------------------------------------------------------------------------
// index — is_disabled, deleted_at, include_deleted filter
// -------------------------------------------------------------------------

public function test_parent_index_response_includes_is_disabled_and_deleted_at(): void
{
    $parent = ParentUser::factory()->disabled()->create();

    Sanctum::actingAs($this->admin, ['staff']);
    $response = $this->getJson('/api/v1/references/parents');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'full_name', 'email', 'is_activated', 'is_disabled', 'deleted_at']],
    ]);
    $response->assertJsonFragment(['is_disabled' => true, 'deleted_at' => null]);
}

public function test_parent_index_excludes_soft_deleted_parents_by_default(): void
{
    $active = ParentUser::factory()->create();
    $deleted = ParentUser::factory()->create();
    $deleted->delete();

    Sanctum::actingAs($this->admin, ['staff']);
    $response = $this->getJson('/api/v1/references/parents');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    $this->assertTrue($ids->contains($active->id));
    $this->assertFalse($ids->contains($deleted->id));
}

public function test_parent_index_includes_soft_deleted_when_include_deleted_is_true(): void
{
    $active = ParentUser::factory()->create();
    $deleted = ParentUser::factory()->create();
    $deleted->delete();

    Sanctum::actingAs($this->admin, ['staff']);
    $response = $this->getJson('/api/v1/references/parents?include_deleted=1');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    $this->assertTrue($ids->contains($active->id));
    $this->assertTrue($ids->contains($deleted->id));
}

// -------------------------------------------------------------------------
// show — is_disabled, deleted_at
// -------------------------------------------------------------------------

public function test_show_response_includes_is_disabled_and_deleted_at(): void
{
    $parent = ParentUser::factory()->disabled()->create();

    $response = $this->asAdmin()->getJson("/api/v1/references/parents/{$parent->id}");

    $response->assertOk();
    $response->assertJsonPath('is_disabled', true);
    $response->assertJsonPath('deleted_at', null);
}

public function test_show_can_view_a_soft_deleted_parent(): void
{
    $parent = ParentUser::factory()->create();
    $parent->delete();

    $response = $this->asAdmin()->getJson("/api/v1/references/parents/{$parent->id}");

    $response->assertOk();
    $this->assertNotNull($response->json('deleted_at'));
}
```

- [ ] **Step 2: Run the tests — expect failure**

```bash
vendor/bin/sail artisan test --compact --filter=test_parent_index_response_includes_is_disabled_and_deleted_at
```

Expected: FAIL — response missing `is_disabled` field.

- [ ] **Step 3: Update ParentController::index**

Replace the entire `index` method in `ParentController.php`:

```php
public function index(Request $request): JsonResponse
{
    $validated = $request->validate([
        'search' => ['nullable', 'string', 'max:100'],
        'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        'include_deleted' => ['nullable', 'boolean'],
    ]);

    $query = ParentUser::query();

    if (! empty($validated['include_deleted'])) {
        $query->withTrashed();
    }

    $query->with('students:id,first_name,last_name,student_number,branch_id')
        ->orderBy('last_name')
        ->orderBy('first_name');

    if (app()->bound('active_branch')) {
        $query->whereHas('students', fn ($q) => $q->where('students.branch_id', app('active_branch')->id)
        );
    }

    if (! empty($validated['search'])) {
        $search = '%'.mb_strtolower($validated['search']).'%';
        $query->where(fn ($q) => $q->whereRaw('lower(first_name) like ?', [$search])
            ->orWhereRaw('lower(last_name) like ?', [$search])
            ->orWhereRaw('lower(email) like ?', [$search])
        );
    }

    $parents = $query->paginate($validated['per_page'] ?? 25);

    return response()->json([
        'data' => collect($parents->items())->map(fn ($parent) => [
            'id' => $parent->id,
            'full_name' => $parent->full_name,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'is_activated' => $parent->isActivated(),
            'is_disabled' => $parent->isDisabled(),
            'deleted_at' => $parent->deleted_at?->toISOString(),
            'students_count' => $parent->students->count(),
            'students' => $parent->students->map(fn ($s) => [
                'id' => $s->id,
                'student_number' => $s->student_number,
                'full_name' => $s->full_name,
            ]),
        ]),
        'meta' => $this->paginationMeta($parents),
    ]);
}
```

- [ ] **Step 4: Update ParentController::show**

Replace the `show` method:

```php
public function show(ParentUser $parent): JsonResponse
{
    $parent->load(['students:id,first_name,last_name,student_number,grade_level,branch_id', 'students.branch:id,name']);

    $contact = StudentContact::where('email', $parent->email)->latest()->first();

    return response()->json([
        'id' => $parent->id,
        'full_name' => $parent->full_name,
        'email' => $parent->email,
        'phone' => $parent->phone ?? $contact?->phone,
        'address' => $parent->address ?? $contact?->address,
        'profile_photo_url' => $parent->profile_photo_url,
        'is_activated' => $parent->isActivated(),
        'is_disabled' => $parent->isDisabled(),
        'deleted_at' => $parent->deleted_at?->toISOString(),
        'created_at' => $parent->created_at,
        'students' => $parent->students->map(fn ($s) => [
            'id' => $s->id,
            'student_number' => $s->student_number,
            'full_name' => $s->full_name,
            'grade_level' => $s->grade_level,
            'branch_name' => $s->branch?->name,
            'wallet_alert_threshold' => (float) $s->pivot->wallet_alert_threshold,
            'linked_at' => $s->pivot->linked_at,
        ]),
    ]);
}
```

- [ ] **Step 5: Add withTrashed to the show route**

In `routes/kitchen-api.php`, update the show route:

```php
Route::get('/references/parents/{parent}', [ParentController::class, 'show'])->withTrashed();
```

- [ ] **Step 6: Run all index/show tests — expect pass**

```bash
vendor/bin/sail artisan test --compact --filter=ParentAccountManagementTest::test_parent_index_response_includes_is_disabled_and_deleted_at
vendor/bin/sail artisan test --compact --filter=ParentAccountManagementTest::test_parent_index_excludes_soft_deleted_parents_by_default
vendor/bin/sail artisan test --compact --filter=ParentAccountManagementTest::test_parent_index_includes_soft_deleted_when_include_deleted_is_true
vendor/bin/sail artisan test --compact --filter=ParentAccountManagementTest::test_show_response_includes_is_disabled_and_deleted_at
vendor/bin/sail artisan test --compact --filter=ParentAccountManagementTest::test_show_can_view_a_soft_deleted_parent
```

Expected: all PASS.

- [ ] **Step 7: Run pint and commit**

```bash
vendor/bin/sail bin pint --dirty --format agent
git add app/Http/Controllers/Kitchen/ParentController.php routes/kitchen-api.php tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "feat: add is_disabled, deleted_at to index/show responses; add include_deleted filter"
```

---

## Task 9: Authorization and edge case tests

**Files:**
- Modify: `tests/Feature/Kitchen/ParentAccountManagementTest.php`

- [ ] **Step 1: Add authorization and edge case tests**

Append to `ParentAccountManagementTest.php`:

```php
// -------------------------------------------------------------------------
// authorization — supervisor and unauthenticated
// -------------------------------------------------------------------------

public function test_supervisor_cannot_disable_a_parent(): void
{
    $parent = ParentUser::factory()->create();

    $response = $this->asUserWithRole('supervisor')
        ->postJson("/api/v1/references/parents/{$parent->id}/disable");

    $response->assertForbidden();
}

public function test_supervisor_cannot_enable_a_parent(): void
{
    $parent = ParentUser::factory()->disabled()->create();

    $response = $this->asUserWithRole('supervisor')
        ->postJson("/api/v1/references/parents/{$parent->id}/enable");

    $response->assertForbidden();
}

public function test_supervisor_cannot_delete_a_parent(): void
{
    $parent = ParentUser::factory()->create();

    $response = $this->asUserWithRole('supervisor')
        ->deleteJson("/api/v1/references/parents/{$parent->id}");

    $response->assertForbidden();
}

public function test_supervisor_cannot_restore_a_parent(): void
{
    $parent = ParentUser::factory()->create();
    $parent->delete();

    $response = $this->asUserWithRole('supervisor')
        ->postJson("/api/v1/references/parents/{$parent->id}/restore");

    $response->assertForbidden();
}

public function test_unauthenticated_user_cannot_disable_a_parent(): void
{
    $parent = ParentUser::factory()->create();

    $response = $this->postJson("/api/v1/references/parents/{$parent->id}/disable");

    $response->assertUnauthorized();
}

// -------------------------------------------------------------------------
// edge cases
// -------------------------------------------------------------------------

public function test_disabling_an_already_disabled_parent_is_idempotent(): void
{
    $parent = ParentUser::factory()->disabled()->create();

    $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/disable");

    $response->assertOk();
    $this->assertNotNull($parent->fresh()->disabled_at);
}

public function test_enabling_an_already_enabled_parent_resets_activation_and_sends_mail(): void
{
    Mail::fake();

    $parent = ParentUser::factory()->create();

    $response = $this->asAdmin()->postJson("/api/v1/references/parents/{$parent->id}/enable");

    $response->assertOk();
    $this->assertNull($parent->fresh()->email_verified_at);
    Mail::assertQueued(ParentWelcomeMail::class);
}
```

- [ ] **Step 2: Run all new tests**

```bash
vendor/bin/sail artisan test --compact tests/Feature/Kitchen/ParentAccountManagementTest.php
```

Expected: all PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Kitchen/ParentAccountManagementTest.php
git commit -m "test: add authorization and edge case tests for parent account management"
```

---

## Task 10: Full backend test suite

- [ ] **Step 1: Run entire test suite**

```bash
vendor/bin/sail artisan test --compact
```

Expected: all tests PASS. Fix any regressions before continuing.

- [ ] **Step 2: Final pint pass**

```bash
vendor/bin/sail bin pint --dirty --format agent
```

- [ ] **Step 3: Commit if pint made changes**

```bash
git add -u && git diff --cached --quiet || git commit -m "style: apply pint formatting"
```

---

## Task 11: Frontend — update types

**Files:**
- Modify: `~/sunbites-pos/types/parent.ts`

- [ ] **Step 1: Add is_disabled and deleted_at to Parent interface**

Open `types/parent.ts` and update `Parent`:

```typescript
export interface Parent {
  id: number;
  full_name: string;
  email: string;
  phone: string | null;
  is_activated: boolean;
  is_disabled: boolean;
  deleted_at: string | null;
  students_count: number;
  students: {
    id: number;
    student_number: string;
    full_name: string;
  }[];
}

export interface ParentDetail extends Omit<Parent, "students"> {
  address: string | null;
  profile_photo_url: string | null;
  created_at: string;
  students: {
    id: number;
    student_number: string;
    full_name: string;
    grade_level: string;
    branch_name: string;
    wallet_alert_threshold: number;
    linked_at: string;
  }[];
}

export interface PaginatedParents {
  data: Parent[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}
```

- [ ] **Step 2: Type-check**

```bash
cd ~/sunbites-pos && npm run type-check
```

Expected: no errors (TypeScript may complain about missing fields in test fixtures — fix those in later tasks).

- [ ] **Step 3: Commit**

```bash
git add types/parent.ts
git commit -m "feat: add is_disabled and deleted_at to Parent types"
```

---

## Task 12: Frontend — update API service

**Files:**
- Modify: `~/sunbites-pos/lib/api/parents.ts`

- [ ] **Step 1: Add the four new API calls**

Replace `lib/api/parents.ts` with:

```typescript
import { apiClient } from "./client";

import type { PaginatedParents, ParentDetail } from "@/types/parent";

interface ParentListParams {
  search?: string;
  per_page?: number;
  page?: number;
  include_deleted?: boolean;
}

export const parentApi = {
  list: (params?: ParentListParams) =>
    apiClient.get<PaginatedParents>("/references/parents", {
      params: params as Record<string, string | number | boolean | undefined>,
    }),

  show: (id: number) =>
    apiClient.get<ParentDetail>(`/references/parents/${id}`),

  resendActivation: (id: number) =>
    apiClient.post<{ message: string }>(
      `/references/parents/${id}/resend-activation`,
    ),

  disable: (id: number) =>
    apiClient.post<{ message: string }>(
      `/references/parents/${id}/disable`,
    ),

  enable: (id: number) =>
    apiClient.post<{ message: string }>(
      `/references/parents/${id}/enable`,
    ),

  destroy: (id: number) =>
    apiClient.delete<{ message: string }>(`/references/parents/${id}`),

  restore: (id: number) =>
    apiClient.post<{ message: string }>(
      `/references/parents/${id}/restore`,
    ),
};
```

- [ ] **Step 2: Type-check and lint**

```bash
cd ~/sunbites-pos
npm run type-check
npm run lint
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add lib/api/parents.ts
git commit -m "feat: add disable, enable, destroy, restore to parentApi"
```

---

## Task 13: Frontend — parent list page updates

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/references/parents/page.tsx`

- [ ] **Step 1: Update the parent list page**

Replace the entire file content:

```typescript
"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { parentApi } from "@/lib/api/parents";
import { cn } from "@/lib/utils";

import type { Parent } from "@/types/parent";

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function ParentTableSkeleton() {
  return (
    <>
      {Array.from({ length: 5 }).map((_, i) => (
        <tr key={i}>
          <td className="px-4 py-3">
            <Skeleton className="h-4 w-40" />
          </td>
          <td className="px-4 py-3">
            <Skeleton className="h-4 w-48" />
          </td>
          <td className="px-4 py-3">
            <Skeleton className="h-4 w-12" />
          </td>
          <td className="px-4 py-3">
            <Skeleton className="h-5 w-20 rounded-full" />
          </td>
          <td className="px-4 py-3">
            <Skeleton className="h-8 w-16 rounded-md" />
          </td>
        </tr>
      ))}
    </>
  );
}

function StatusBadge({ parent }: { parent: Parent }) {
  if (parent.deleted_at) {
    return (
      <span className="text-[11px] font-bold px-2 py-0.5 rounded-full border bg-red-100 text-red-700 border-red-300">
        Deleted
      </span>
    );
  }

  if (parent.is_disabled) {
    return (
      <span className="text-[11px] font-bold px-2 py-0.5 rounded-full border bg-orange-100 text-orange-700 border-orange-300">
        Disabled
      </span>
    );
  }

  return (
    <span
      className={cn(
        "text-[11px] font-bold px-2 py-0.5 rounded-full border",
        parent.is_activated
          ? "bg-green-100 text-green-700 border-green-300"
          : "bg-muted text-muted-foreground border-border",
      )}
    >
      {parent.is_activated ? "Active" : "Pending"}
    </span>
  );
}

function ParentRow({
  parent,
  onClick,
}: {
  parent: Parent;
  onClick: () => void;
}) {
  return (
    <tr
      className={cn(
        "border-b border-border transition-colors hover:bg-muted/30 cursor-pointer",
        parent.deleted_at && "opacity-60",
      )}
      onClick={onClick}
    >
      <td className="px-4 py-3">
        <div className="flex items-center gap-3">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
            {parent.full_name.charAt(0).toUpperCase()}
          </div>
          <p className="font-medium text-foreground">{parent.full_name}</p>
        </div>
      </td>
      <td className="px-4 py-3 text-sm text-muted-foreground">
        {parent.email}
      </td>
      <td className="px-4 py-3 text-sm text-center">{parent.students_count}</td>
      <td className="px-4 py-3">
        <StatusBadge parent={parent} />
      </td>
      <td className="px-4 py-3 text-right">
        <Button
          variant="outline"
          size="sm"
          onClick={(e) => {
            e.stopPropagation();
            onClick();
          }}
        >
          View
        </Button>
      </td>
    </tr>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function ParentsPage() {
  const router = useRouter();
  const [search, setSearch] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [page, setPage] = useState(1);
  const [showDeleted, setShowDeleted] = useState(false);
  const debounceTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    return () => {
      if (debounceTimer.current) clearTimeout(debounceTimer.current);
    };
  }, []);

  const handleSearchChange = useCallback((value: string) => {
    setSearch(value);
    if (debounceTimer.current) clearTimeout(debounceTimer.current);
    debounceTimer.current = setTimeout(() => {
      setDebouncedSearch(value);
      setPage(1);
    }, 300);
  }, []);

  const { data, isLoading, isError } = useQuery({
    queryKey: ["parents", { search: debouncedSearch, page, showDeleted }],
    queryFn: () =>
      parentApi.list({
        search: debouncedSearch || undefined,
        page,
        include_deleted: showDeleted || undefined,
      }),
  });

  const meta = data?.meta;

  return (
    <div className="p-6 space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs text-muted-foreground">References</p>
          <h1 className="text-xl font-bold text-foreground">
            Parent Management
          </h1>
        </div>
        <div className="flex items-center gap-3">
          <label className="flex items-center gap-2 text-sm text-muted-foreground cursor-pointer select-none">
            <input
              type="checkbox"
              checked={showDeleted}
              onChange={(e) => {
                setShowDeleted(e.target.checked);
                setPage(1);
              }}
              className="rounded border-border"
              aria-label="Show deleted parents"
            />
            Show deleted
          </label>
          <Input
            placeholder="Search by name or email…"
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="max-w-xs"
            aria-label="Search parents"
          />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-lg border border-border bg-card overflow-x-auto">
        {isError ? (
          <div className="px-4 py-8 text-center text-sm text-destructive">
            Failed to load parents. Please try again.
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border bg-muted/40">
                <th className="px-4 py-3 text-left font-semibold text-muted-foreground">
                  Name
                </th>
                <th className="px-4 py-3 text-left font-semibold text-muted-foreground">
                  Email
                </th>
                <th className="px-4 py-3 text-center font-semibold text-muted-foreground">
                  Students
                </th>
                <th className="px-4 py-3 text-left font-semibold text-muted-foreground">
                  Status
                </th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                <ParentTableSkeleton />
              ) : !data?.data?.length ? (
                <tr>
                  <td
                    colSpan={5}
                    className="px-4 py-10 text-center text-muted-foreground"
                  >
                    No parents found.
                  </td>
                </tr>
              ) : (
                data.data.map((parent) => (
                  <ParentRow
                    key={parent.id}
                    parent={parent}
                    onClick={() =>
                      router.push(`/references/parents/${parent.id}`)
                    }
                  />
                ))
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
          </span>
          <div className="flex gap-1">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              aria-label="Previous page"
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="flex items-center px-2 font-medium text-foreground">
              {page} / {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page}
              aria-label="Next page"
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Type-check and lint**

```bash
cd ~/sunbites-pos
npm run type-check
npm run lint
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add app/\(kitchen\)/references/parents/page.tsx
git commit -m "feat: add status badges and show-deleted toggle to parent list"
```

---

## Task 14: Frontend — parent detail page updates

**Files:**
- Modify: `~/sunbites-pos/app/(kitchen)/references/parents/[id]/page.tsx`

- [ ] **Step 1: Replace the parent detail page**

Replace the entire file:

```typescript
"use client";

import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronLeft, Mail, ShieldOff, ShieldCheck, Trash2, RotateCcw } from "lucide-react";
import { toast } from "sonner";

import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { parentApi } from "@/lib/api/parents";
import { cn } from "@/lib/utils";

import type { ApiError } from "@/types/auth";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function parsePositiveInt(raw: string | undefined | null): number | null {
  if (!raw) return null;
  if (!/^\d+$/.test(raw)) return null;
  const n = parseInt(raw, 10);
  return n > 0 ? n : null;
}

function DetailSkeleton() {
  return (
    <div className="p-6 space-y-4">
      <Skeleton className="h-8 w-48" />
      <Skeleton className="h-40 w-full rounded-xl" />
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}

function InfoRow({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="py-2">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="mt-0.5 text-sm font-medium text-foreground">
        {value || "—"}
      </p>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

export default function ParentDetailPage() {
  const routerParams = useParams<{ id: string }>();
  const parentId = parsePositiveInt(routerParams?.id);
  const router = useRouter();
  const queryClient = useQueryClient();

  const {
    data: parent,
    isLoading,
    isError,
  } = useQuery({
    queryKey: ["parent", parentId],
    queryFn: () => parentApi.show(parentId!),
    enabled: parentId !== null,
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ["parent", parentId] });
    queryClient.invalidateQueries({ queryKey: ["parents"] });
  };

  const resendMutation = useMutation({
    mutationFn: () => parentApi.resendActivation(parentId!),
    onSuccess: () => {
      invalidate();
      toast.success("Activation email resent.");
    },
    onError: (err: ApiError) => {
      toast.error(err.message ?? "Failed to resend activation email.");
    },
  });

  const disableMutation = useMutation({
    mutationFn: () => parentApi.disable(parentId!),
    onSuccess: () => {
      invalidate();
      toast.success("Parent access disabled.");
    },
    onError: (err: ApiError) => {
      toast.error(err.message ?? "Failed to disable parent.");
    },
  });

  const enableMutation = useMutation({
    mutationFn: () => parentApi.enable(parentId!),
    onSuccess: () => {
      invalidate();
      toast.success("Parent re-enabled. Activation email sent.");
    },
    onError: (err: ApiError) => {
      toast.error(err.message ?? "Failed to enable parent.");
    },
  });

  const destroyMutation = useMutation({
    mutationFn: () => parentApi.destroy(parentId!),
    onSuccess: () => {
      invalidate();
      toast.success("Parent account deleted.");
      router.push("/references/parents");
    },
    onError: (err: ApiError) => {
      toast.error(err.message ?? "Failed to delete parent.");
    },
  });

  const restoreMutation = useMutation({
    mutationFn: () => parentApi.restore(parentId!),
    onSuccess: () => {
      invalidate();
      toast.success("Parent restored. Activation email sent.");
    },
    onError: (err: ApiError) => {
      toast.error(err.message ?? "Failed to restore parent.");
    },
  });

  const anyPending =
    resendMutation.isPending ||
    disableMutation.isPending ||
    enableMutation.isPending ||
    destroyMutation.isPending ||
    restoreMutation.isPending;

  if (parentId === null) {
    return (
      <div className="p-6">
        <div className="rounded-lg border border-border bg-card px-6 py-10 text-center">
          <p className="text-sm text-destructive">Invalid parent ID.</p>
        </div>
      </div>
    );
  }

  if (isLoading) return <DetailSkeleton />;

  if (isError || !parent) {
    return (
      <div className="p-6">
        <div className="rounded-lg border border-border bg-card px-6 py-10 text-center">
          <p className="text-sm text-destructive">
            Failed to load parent. Please try again.
          </p>
          <Button
            variant="outline"
            size="sm"
            className="mt-4"
            onClick={() => router.push("/references/parents")}
          >
            Back to Parents
          </Button>
        </div>
      </div>
    );
  }

  const isDeleted = parent.deleted_at !== null;

  return (
    <div className="p-6 space-y-6">
      {/* Back + actions */}
      <div className="flex items-center justify-between flex-wrap gap-2">
        <Link
          href="/references/parents"
          className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          Parents
        </Link>

        <div className="flex items-center gap-2 flex-wrap">
          {!parent.is_activated && !isDeleted && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => resendMutation.mutate()}
              disabled={anyPending}
            >
              <Mail className="mr-1.5 h-4 w-4" aria-hidden="true" />
              {resendMutation.isPending ? "Sending…" : "Resend Activation"}
            </Button>
          )}

          {!isDeleted && !parent.is_disabled && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => disableMutation.mutate()}
              disabled={anyPending}
            >
              <ShieldOff className="mr-1.5 h-4 w-4" aria-hidden="true" />
              {disableMutation.isPending ? "Disabling…" : "Disable Access"}
            </Button>
          )}

          {!isDeleted && parent.is_disabled && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => enableMutation.mutate()}
              disabled={anyPending}
            >
              <ShieldCheck className="mr-1.5 h-4 w-4" aria-hidden="true" />
              {enableMutation.isPending ? "Enabling…" : "Enable Access"}
            </Button>
          )}

          {!isDeleted && (
            <Button
              type="button"
              size="sm"
              variant="destructive"
              onClick={() => destroyMutation.mutate()}
              disabled={anyPending}
            >
              <Trash2 className="mr-1.5 h-4 w-4" aria-hidden="true" />
              {destroyMutation.isPending ? "Deleting…" : "Delete"}
            </Button>
          )}

          {isDeleted && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => restoreMutation.mutate()}
              disabled={anyPending}
            >
              <RotateCcw className="mr-1.5 h-4 w-4" aria-hidden="true" />
              {restoreMutation.isPending ? "Restoring…" : "Restore Account"}
            </Button>
          )}
        </div>
      </div>

      {/* Header card */}
      <div className="rounded-xl border border-border bg-card p-6">
        <div className="flex items-start gap-4">
          <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-primary/10 text-2xl font-bold text-primary">
            {parent.full_name.charAt(0).toUpperCase()}
          </div>
          <div className="space-y-2">
            <div className="flex items-center gap-3 flex-wrap">
              <h1 className="text-xl font-bold text-foreground">
                {parent.full_name}
              </h1>
              {isDeleted ? (
                <span className="text-[11px] font-bold px-2 py-0.5 rounded-full border bg-red-100 text-red-700 border-red-300">
                  Deleted
                </span>
              ) : parent.is_disabled ? (
                <span className="text-[11px] font-bold px-2 py-0.5 rounded-full border bg-orange-100 text-orange-700 border-orange-300">
                  Disabled
                </span>
              ) : (
                <span
                  className={cn(
                    "text-[11px] font-bold px-2 py-0.5 rounded-full border",
                    parent.is_activated
                      ? "bg-green-100 text-green-700 border-green-300"
                      : "bg-muted text-muted-foreground border-border",
                  )}
                >
                  {parent.is_activated ? "Active" : "Pending Activation"}
                </span>
              )}
            </div>
            <p className="text-sm text-muted-foreground">{parent.email}</p>
          </div>
        </div>

        <div className="mt-5 grid grid-cols-1 gap-x-8 sm:grid-cols-2 divide-y divide-border">
          <InfoRow label="Phone" value={parent.phone} />
          <InfoRow label="Address" value={parent.address} />
          <InfoRow
            label="Member Since"
            value={new Date(parent.created_at).toLocaleDateString("en-PH", {
              year: "numeric",
              month: "long",
              day: "numeric",
            })}
          />
          {isDeleted && (
            <InfoRow
              label="Deleted At"
              value={new Date(parent.deleted_at!).toLocaleDateString("en-PH", {
                year: "numeric",
                month: "long",
                day: "numeric",
              })}
            />
          )}
        </div>
      </div>

      {/* Linked Students */}
      <div className="rounded-xl border border-border bg-card p-5">
        <h2 className="text-xs font-extrabold uppercase tracking-wider text-muted-foreground mb-3 pb-2 border-b border-border">
          Linked Students ({parent.students.length})
        </h2>

        {parent.students.length === 0 ? (
          <p className="text-sm text-muted-foreground">No linked students.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-border bg-muted/40">
                  <th className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground">
                    Student No.
                  </th>
                  <th className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground">
                    Name
                  </th>
                  <th className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground">
                    Grade
                  </th>
                  <th className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground">
                    Branch
                  </th>
                  <th className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground">
                    Wallet Alert
                  </th>
                  <th className="px-3 py-2 text-left text-xs font-semibold text-muted-foreground">
                    Linked At
                  </th>
                </tr>
              </thead>
              <tbody>
                {parent.students.map((student) => (
                  <tr
                    key={student.id}
                    className="border-b border-border hover:bg-muted/20"
                  >
                    <td className="px-3 py-2 font-mono text-xs text-muted-foreground">
                      {student.student_number}
                    </td>
                    <td className="px-3 py-2 font-medium">
                      {student.full_name}
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">
                      {student.grade_level}
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">
                      {student.branch_name}
                    </td>
                    <td className="px-3 py-2">
                      PHP {Number(student.wallet_alert_threshold).toFixed(2)}
                    </td>
                    <td className="px-3 py-2 text-xs text-muted-foreground">
                      {new Date(student.linked_at).toLocaleDateString("en-PH", {
                        year: "numeric",
                        month: "short",
                        day: "numeric",
                      })}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Type-check and lint**

```bash
cd ~/sunbites-pos
npm run type-check
npm run lint
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add "app/(kitchen)/references/parents/[id]/page.tsx"
git commit -m "feat: add disable, enable, delete, restore actions to parent detail page"
```

---

## Task 15: Frontend — MSW handlers and tests

**Files:**
- Modify: `~/sunbites-pos/__tests__/mocks/handlers.ts`
- Create: `~/sunbites-pos/__tests__/parents/parent-account-management.test.tsx`

- [ ] **Step 1: Add parent mutation handlers to handlers.ts**

At the end of the existing handlers array in `__tests__/mocks/handlers.ts`, add the parent management handlers. First add the import for the parent types at the top if not already imported, then add the handlers:

```typescript
// Add at the end of the handlers array:

http.get(`${API}/references/parents`, () =>
  HttpResponse.json({
    data: [
      {
        id: 1,
        full_name: "Maria Santos",
        email: "maria@example.com",
        phone: null,
        is_activated: true,
        is_disabled: false,
        deleted_at: null,
        students_count: 1,
        students: [{ id: 1, student_number: "STU-001", full_name: "Juan Santos" }],
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
  }),
),

http.get(`${API}/references/parents/:id`, () =>
  HttpResponse.json({
    id: 1,
    full_name: "Maria Santos",
    email: "maria@example.com",
    phone: null,
    address: null,
    profile_photo_url: null,
    is_activated: true,
    is_disabled: false,
    deleted_at: null,
    created_at: "2025-01-01T00:00:00.000000Z",
    students: [],
  }),
),

http.post(`${API}/references/parents/:id/disable`, () =>
  HttpResponse.json({ message: "Parent access disabled." }),
),

http.post(`${API}/references/parents/:id/enable`, () =>
  HttpResponse.json({ message: "Parent access enabled. Activation email sent." }),
),

http.delete(`${API}/references/parents/:id`, () =>
  HttpResponse.json({ message: "Parent account deleted." }),
),

http.post(`${API}/references/parents/:id/restore`, () =>
  HttpResponse.json({ message: "Parent account restored. Activation email sent." }),
),

http.post(`${API}/references/parents/:id/resend-activation`, () =>
  HttpResponse.json({ message: "Activation email sent." }),
),
```

- [ ] **Step 2: Create the test directory and test file**

```bash
mkdir -p ~/sunbites-pos/__tests__/parents
```

Create `__tests__/parents/parent-account-management.test.tsx`:

```typescript
import { http, HttpResponse } from "msw";
import { server } from "@/__tests__/mocks/server";
import { render, screen, waitFor } from "@/__tests__/test-utils";
import userEvent from "@testing-library/user-event";

import ParentsPage from "@/app/(kitchen)/references/parents/page";
import ParentDetailPage from "@/app/(kitchen)/references/parents/[id]/page";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

// ---------------------------------------------------------------------------
// Mocks for Next.js navigation
// ---------------------------------------------------------------------------

const mockPush = jest.fn();

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: mockPush }),
  useParams: () => ({ id: "1" }),
}));

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const activeParent = {
  id: 1,
  full_name: "Maria Santos",
  email: "maria@example.com",
  phone: null,
  address: null,
  profile_photo_url: null,
  is_activated: true,
  is_disabled: false,
  deleted_at: null,
  created_at: "2025-01-01T00:00:00.000000Z",
  students: [],
};

const disabledParent = { ...activeParent, is_disabled: true };
const deletedParent = { ...activeParent, deleted_at: "2025-06-01T00:00:00.000000Z" };

// ---------------------------------------------------------------------------
// Parent List Page — status badges
// ---------------------------------------------------------------------------

describe("ParentsPage — status badges", () => {
  it("shows Active badge for an active, enabled parent", async () => {
    server.use(
      http.get(`${API}/references/parents`, () =>
        HttpResponse.json({
          data: [{ ...activeParent, students_count: 0, students: [] }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
        }),
      ),
    );

    render(<ParentsPage />);

    expect(await screen.findByText("Active")).toBeInTheDocument();
  });

  it("shows Disabled badge when is_disabled is true", async () => {
    server.use(
      http.get(`${API}/references/parents`, () =>
        HttpResponse.json({
          data: [{ ...disabledParent, students_count: 0, students: [] }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
        }),
      ),
    );

    render(<ParentsPage />);

    expect(await screen.findByText("Disabled")).toBeInTheDocument();
  });

  it("shows Deleted badge when deleted_at is set", async () => {
    server.use(
      http.get(`${API}/references/parents`, () =>
        HttpResponse.json({
          data: [{ ...deletedParent, students_count: 0, students: [] }],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
        }),
      ),
    );

    render(<ParentsPage />);

    expect(await screen.findByText("Deleted")).toBeInTheDocument();
  });

  it("passes include_deleted=true when Show deleted checkbox is checked", async () => {
    const user = userEvent.setup();
    let capturedUrl = "";

    server.use(
      http.get(`${API}/references/parents`, ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
        });
      }),
    );

    render(<ParentsPage />);

    const checkbox = await screen.findByRole("checkbox", { name: /show deleted/i });
    await user.click(checkbox);

    await waitFor(() => {
      expect(capturedUrl).toContain("include_deleted=true");
    });
  });
});

// ---------------------------------------------------------------------------
// Parent Detail Page — action buttons
// ---------------------------------------------------------------------------

describe("ParentDetailPage — action buttons", () => {
  it("shows Disable Access button for an active parent", async () => {
    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(activeParent)),
    );

    render(<ParentDetailPage />);

    expect(await screen.findByRole("button", { name: /disable access/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /enable access/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /restore account/i })).not.toBeInTheDocument();
  });

  it("shows Enable Access button (not Disable) for a disabled parent", async () => {
    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(disabledParent)),
    );

    render(<ParentDetailPage />);

    expect(await screen.findByRole("button", { name: /enable access/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /disable access/i })).not.toBeInTheDocument();
  });

  it("shows Restore Account button (not Delete or Disable) for a deleted parent", async () => {
    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(deletedParent)),
    );

    render(<ParentDetailPage />);

    expect(await screen.findByRole("button", { name: /restore account/i })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /disable access/i })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /delete/i })).not.toBeInTheDocument();
  });

  it("calls disable endpoint and shows success toast when Disable is clicked", async () => {
    const user = userEvent.setup();
    let disableCalled = false;

    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(activeParent)),
      http.post(`${API}/references/parents/1/disable`, () => {
        disableCalled = true;
        return HttpResponse.json({ message: "Parent access disabled." });
      }),
    );

    render(<ParentDetailPage />);

    const disableBtn = await screen.findByRole("button", { name: /disable access/i });
    await user.click(disableBtn);

    await waitFor(() => {
      expect(disableCalled).toBe(true);
    });
  });

  it("calls enable endpoint when Enable Access is clicked", async () => {
    const user = userEvent.setup();
    let enableCalled = false;

    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(disabledParent)),
      http.post(`${API}/references/parents/1/enable`, () => {
        enableCalled = true;
        return HttpResponse.json({ message: "Parent access enabled. Activation email sent." });
      }),
    );

    render(<ParentDetailPage />);

    const enableBtn = await screen.findByRole("button", { name: /enable access/i });
    await user.click(enableBtn);

    await waitFor(() => {
      expect(enableCalled).toBe(true);
    });
  });

  it("calls delete endpoint and navigates to list when Delete is clicked", async () => {
    const user = userEvent.setup();
    let deleteCalled = false;

    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(activeParent)),
      http.delete(`${API}/references/parents/1`, () => {
        deleteCalled = true;
        return HttpResponse.json({ message: "Parent account deleted." });
      }),
    );

    render(<ParentDetailPage />);

    const deleteBtn = await screen.findByRole("button", { name: /delete/i });
    await user.click(deleteBtn);

    await waitFor(() => {
      expect(deleteCalled).toBe(true);
      expect(mockPush).toHaveBeenCalledWith("/references/parents");
    });
  });

  it("calls restore endpoint when Restore Account is clicked", async () => {
    const user = userEvent.setup();
    let restoreCalled = false;

    server.use(
      http.get(`${API}/references/parents/1`, () => HttpResponse.json(deletedParent)),
      http.post(`${API}/references/parents/1/restore`, () => {
        restoreCalled = true;
        return HttpResponse.json({ message: "Parent account restored. Activation email sent." });
      }),
    );

    render(<ParentDetailPage />);

    const restoreBtn = await screen.findByRole("button", { name: /restore account/i });
    await user.click(restoreBtn);

    await waitFor(() => {
      expect(restoreCalled).toBe(true);
    });
  });
});
```

- [ ] **Step 3: Run the frontend tests**

```bash
cd ~/sunbites-pos && npx jest __tests__/parents/parent-account-management.test.tsx --no-coverage
```

Expected: all tests PASS. Fix any failures before continuing.

- [ ] **Step 4: Commit**

```bash
git add __tests__/mocks/handlers.ts __tests__/parents/parent-account-management.test.tsx
git commit -m "test: add frontend tests for parent account management"
```

---

## Task 16: Frontend quality gates

- [ ] **Step 1: Full type-check**

```bash
cd ~/sunbites-pos && npm run type-check
```

Expected: no TypeScript errors.

- [ ] **Step 2: Lint**

```bash
cd ~/sunbites-pos && npm run lint
```

Expected: no lint errors.

- [ ] **Step 3: Format check**

```bash
cd ~/sunbites-pos && npm run format:check
```

If formatting issues found, run:

```bash
npm run format
git add -u && git commit -m "style: apply prettier formatting"
```

- [ ] **Step 4: Full test suite with coverage**

```bash
cd ~/sunbites-pos && npm run test:coverage
```

Expected: all tests pass, coverage thresholds met (80% branches/functions/lines/statements).

- [ ] **Step 5: Final commit (if any changes)**

```bash
git add -u && git diff --cached --quiet || git commit -m "style: formatting and coverage fixes"
```

---

## Self-Review

**Spec coverage check:**
- ✅ Enable/disable parent access — Tasks 3, 4, 7, 13, 14
- ✅ Soft-delete parent account — Task 5
- ✅ Restore with forced password reset + activation mail — Task 6
- ✅ Reject disabled parent at login (`account_disabled`) — Task 7
- ✅ `disabled_at` timestamp column for audit — Task 1
- ✅ Token revocation on disable and delete — Tasks 3, 5 (in action classes)
- ✅ `is_disabled` + `deleted_at` in index and show responses — Task 8
- ✅ Show-deleted toggle (frontend) — Task 13
- ✅ Action buttons on detail page — Task 14
- ✅ Frontend tests — Task 15
- ✅ Authorization tests (supervisor = 403) — Task 9
- ✅ Edge cases (idempotent disable, non-deleted restore = 404) — Task 9
- ✅ Quality gates — Task 16
- ✅ Auto re-enable on student restore: explicitly NOT included (manual only — by design decision)

**Placeholder scan:** No TBDs or incomplete steps found.

**Type consistency:** `DisableParentAction`, `EnableParentAction`, `SoftDeleteParentAction`, `RestoreParentAction` — named consistently across action files, controller imports, and test descriptions. `parentApi.disable/enable/destroy/restore` match the API service definitions.
