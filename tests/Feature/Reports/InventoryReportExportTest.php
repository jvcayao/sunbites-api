<?php

namespace Tests\Feature\Reports;

use App\Enums\InventoryLogType;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * A real download, without Excel::fake(): the fake skips the `Export` type check on
 * Excel::download(), which is exactly what broke this endpoint (Spec 16 B-19).
 */
class InventoryReportExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    public function test_manager_downloads_the_inventory_report_as_a_two_sheet_workbook(): void
    {
        $branch = Branch::factory()->create(['slug' => 'ant', 'is_active' => true]);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $item = InventoryItem::factory()->create(['branch_id' => $branch->id, 'name' => 'Rice', 'quantity' => 50]);
        InventoryLog::create([
            'branch_id' => $branch->id,
            'inventory_item_id' => $item->id,
            'adjusted_by' => $manager->id,
            'type' => InventoryLogType::Restock,
            'quantity_change' => 50,
            'stock_after' => 50,
            'item_name_snapshot' => 'Rice',
            'reason' => 'Initial stock',
            'created_at' => now(),
        ]);
        Sanctum::actingAs($manager, ['staff']);

        $response = $this->withHeaders(['X-Branch-Id' => $branch->id])->get('/api/v1/reports/inventory/export');

        $response->assertOk();
        $this->assertStringContainsString('inventory-report-ant-', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(2, IOFactory::load($response->baseResponse->getFile()->getPathname())->getSheetCount());
    }
}
