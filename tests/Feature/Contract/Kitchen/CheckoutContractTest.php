<?php

namespace Tests\Feature\Contract\Kitchen;

use App\Models\PosMenuItem;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Contract\ContractTestCase;
use Tests\TestCase;

class CheckoutContractTest extends ContractTestCase
{
    private const string ROUTE = 'POST api/v1/pos/checkout';

    public function test_cash_walk_in_checkout_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'payment_method' => 'cash',
            'amount_tendered' => 200,
            'notes' => 'No onions',
        ]));

        $this->assertContract($response, self::ROUTE);
    }

    public function test_wallet_checkout_for_a_student_matches_contract(): void
    {
        Mail::fake();
        Notification::fake();

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $this->scenario->subscriptionStudent->id,
            'payment_method' => 'wallet',
            'notes' => 'Extra rice',
        ]));

        $this->assertContract($response, self::ROUTE, 'ok-wallet');
    }

    public function test_checkout_without_items_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', []);

        $this->assertContract($response, self::ROUTE, '422-validation');
    }

    public function test_walk_in_wallet_checkout_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'wallet']));

        $this->assertContract($response, self::ROUTE, '422-walk-in-wallet');
    }

    public function test_walk_in_subscription_checkout_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart(['payment_method' => 'subscription']));

        $this->assertContract($response, self::ROUTE, '422-walk-in-subscription');
    }

    public function test_checkout_of_an_other_branch_item_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => $this->scenario->branchBMenuItem->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 200,
        ]);

        $this->assertContract($response, self::ROUTE, '422-other-branch-item');
    }

    public function test_checkout_of_an_unmapped_item_matches_contract(): void
    {
        $unmapped = PosMenuItem::factory()->create([
            'branch_id' => $this->scenario->branchA->id,
            'name' => 'Unmapped Snack',
            'price' => 25,
            'category' => 'snack',
        ]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'items' => [['pos_menu_item_id' => $unmapped->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => 50,
        ]);

        $this->assertContract($response, self::ROUTE, '422-unmapped-item');
    }

    public function test_checkout_beyond_stock_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'payment_method' => 'cash',
            'amount_tendered' => 20000,
        ], quantity: 101));

        $this->assertContract($response, self::ROUTE, '422-out-of-stock');
    }

    public function test_cashier_applying_a_discount_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'payment_method' => 'cash',
            'amount_tendered' => 200,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_reason' => 'Promo',
        ]));

        $this->assertContract($response, self::ROUTE, '403-discount-not-allowed');
    }

    public function test_checkout_for_a_non_enrolled_student_matches_contract(): void
    {
        $this->scenario->nonSubscriptionStudent->update(['enrollment_status' => 'banned']);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $this->scenario->nonSubscriptionStudent->id,
            'payment_method' => 'cash',
            'amount_tendered' => 200,
        ]));

        $this->assertContract($response, self::ROUTE, '422-not-enrolled');
    }

    public function test_subscription_checkout_for_a_non_subscription_student_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $this->scenario->nonSubscriptionStudent->id,
            'payment_method' => 'subscription',
        ]));

        $this->assertContract($response, self::ROUTE, '422-subscription-only');
    }

    public function test_subscription_checkout_of_an_ineligible_item_matches_contract(): void
    {
        $ineligible = PosMenuItem::factory()->create([
            'branch_id' => $this->scenario->branchA->id,
            'name' => 'Chocolate Bar',
            'price' => 45,
            'category' => 'snack',
            'is_subscription_item' => false,
        ]);
        $ineligible->inventoryItems()->attach($this->scenario->inventoryItem->id, ['quantity_used' => 1]);

        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', [
            'student_id' => $this->scenario->subscriptionStudent->id,
            'items' => [['pos_menu_item_id' => $ineligible->id, 'quantity' => 1]],
            'payment_method' => 'subscription',
        ]);

        $this->assertContract($response, self::ROUTE, '422-not-subscription-item');
    }

    public function test_subscription_checkout_over_the_daily_limit_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $this->scenario->subscriptionStudent->id,
            'payment_method' => 'subscription',
        ], quantity: 2));

        $this->assertContract($response, self::ROUTE, '422-daily-limit');
    }

    public function test_wallet_checkout_over_the_balance_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $this->scenario->subscriptionStudent->id,
            'payment_method' => 'wallet',
        ], quantity: 3));

        $this->assertContract($response, self::ROUTE, '422-insufficient-wallet');
    }

    public function test_credit_checkout_over_the_credit_limit_matches_contract(): void
    {
        $response = $this->asCashier()->postJson('/api/v1/pos/checkout', $this->cart([
            'student_id' => $this->scenario->subscriptionStudent->id,
            'payment_method' => 'wallet',
            'use_credit' => true,
        ], quantity: 5));

        $this->assertContract($response, self::ROUTE, '422-credit-limit');
    }

    private function asCashier(): TestCase
    {
        return $this->scenario->asStaff($this->scenario->cashier);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function cart(array $overrides, int $quantity = 1): array
    {
        return [
            'items' => [['pos_menu_item_id' => $this->scenario->menuItem->id, 'quantity' => $quantity]],
            ...$overrides,
        ];
    }
}
