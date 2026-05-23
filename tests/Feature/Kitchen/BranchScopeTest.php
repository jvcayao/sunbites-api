<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Concerns\HasBranch;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BranchScopeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_branch_scope_silently_skips_when_no_active_branch(): void
    {
        $this->assertFalse(app()->bound('active_branch'));

        // Querying a model that uses HasBranch must not throw when no active branch is bound
        $scopedModel = new class extends Model
        {
            use HasBranch;

            protected $table = 'branches';

            public $timestamps = true;
        };

        $results = $scopedModel::all();

        $this->assertNotNull($results);
    }

    public function test_withoutbranch_removes_scope_from_query(): void
    {
        $branch = Branch::factory()->create();
        app()->instance('active_branch', $branch);

        $scopedModel = new class extends Model
        {
            use HasBranch;

            protected $table = 'branches';

            public $timestamps = true;
        };

        // With scope applied: query includes WHERE branch_id = ?
        $withScope = $scopedModel::query()->toSql();
        $this->assertStringContainsString('branch_id', $withScope);

        // Without scope: WHERE branch_id clause must be absent
        $withoutScope = $scopedModel::withoutBranch()->toSql();
        $this->assertStringNotContainsString('branch_id', $withoutScope);
    }

    public function test_hasbranch_auto_fills_branch_id_on_create(): void
    {
        $branch = Branch::factory()->create();
        app()->instance('active_branch', $branch);

        $scopedModel = new class extends Model
        {
            use HasBranch;

            protected $table = 'branches';

            protected $fillable = ['name', 'slug', 'branch_id'];

            public $timestamps = true;
        };

        $instance = new $scopedModel;
        $instance->name = 'Test';
        $instance->slug = 'test-auto-fill';

        // Trigger the creating listener manually
        $scopedModel::getEventDispatcher()->dispatch('eloquent.creating: '.$scopedModel::class, $instance);

        $this->assertEquals($branch->id, $instance->branch_id);
    }

    public function test_set_active_branch_middleware_binds_branch_from_session(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)
            ->withSession(['active_branch_id' => $branch->id])
            ->get(route('dashboard'));

        $this->assertTrue(app()->bound('active_branch'));
        $this->assertEquals($branch->id, app('active_branch')->id);
    }

    public function test_set_active_branch_middleware_skips_when_no_session(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('branch-selector'));

        $this->assertFalse(app()->bound('active_branch'));
    }
}
