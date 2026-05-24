<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'pos_menu_item_id' => 1,
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->randomFloat(2, 10, 200),
            'quantity' => 1,
            'line_total' => $this->faker->randomFloat(2, 10, 200),
        ];
    }
}
