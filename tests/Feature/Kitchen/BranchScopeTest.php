<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\Concerns\HasBranch;
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

    public function test_without_branch_scope_removes_branch_id_from_query(): void
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
}
