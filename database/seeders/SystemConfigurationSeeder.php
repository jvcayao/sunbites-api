<?php

namespace Database\Seeders;

use App\Models\SystemConfiguration;
use Illuminate\Database\Seeder;

class SystemConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        SystemConfiguration::upsert([
            [
                'key' => 'daily_meal_rate',
                'value' => '135',
                'type' => 'decimal',
                'label' => 'Daily Meal Rate (₱)',
                'description' => 'Daily rate used to compute monthly subscription amounts when no override is set.',
            ],
            [
                'key' => 'credit_limit',
                'value' => '300',
                'type' => 'decimal',
                'label' => 'Credit Limit (₱)',
                'description' => 'Maximum outstanding credit a student may carry at any time.',
            ],
            [
                'key' => 'loyalty_point_threshold',
                'value' => '1000',
                'type' => 'decimal',
                'label' => 'Loyalty Point Threshold (₱)',
                'description' => 'Amount spent to earn one loyalty point.',
            ],
            [
                'key' => 'payment_reminder_days',
                'value' => '14',
                'type' => 'integer',
                'label' => 'Payment Reminder Days',
                'description' => 'Number of days before a payment is due to send a reminder notification to parents.',
            ],
            [
                'key' => 'pre_registration_expiry_days',
                'value' => '30',
                'type' => 'integer',
                'label' => 'Pre-Registration Expiry Days',
                'description' => 'Number of days before a pending pre-registration expires automatically.',
            ],
        ], ['key'], ['value', 'type', 'label', 'description']);
    }
}
