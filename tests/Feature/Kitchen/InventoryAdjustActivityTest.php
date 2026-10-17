<?php

namespace Tests\Feature\Kitchen;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * A manual stock adjustment writes the same activity rows, in the same order, now that the
 * stock write lives in InventoryStockService (Spec 16 T-7.1, hazard H5).
 */
class InventoryAdjustActivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_adjusting_stock_writes_the_model_update_row_then_the_adjusted_row(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $supervisor = User::factory()->create();
        $supervisor->assignRole('supervisor');
        $supervisor->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $flour = InventoryItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Flour', 'quantity' => 10.25]);
        $lastActivityId = Activity::max('id');
        Sanctum::actingAs($supervisor, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->postJson("/api/v1/pos/inventory/{$flour->id}/adjust", [
            'type' => 'waste',
            'direction' => 'deduct',
            'quantity' => '2.5',
            'reason' => 'Spilled',
        ]);

        $response->assertOk();
        $rows = Activity::where('id', '>', $lastActivityId)->orderBy('id')->get();
        $this->assertSame(
            [['default', 'inventory.updated'], ['inventory', 'inventory.adjusted']],
            $rows->map(fn (Activity $row) => [$row->log_name, $row->description])->all(),
        );
        $this->assertSame(
            ['change' => -2.5, 'item' => 'Flour', 'new_qty' => 7.75, 'old_qty' => 10.25, 'reason' => 'Spilled'],
            $rows[1]->properties->sortKeys()->all(),
        );
    }
}
