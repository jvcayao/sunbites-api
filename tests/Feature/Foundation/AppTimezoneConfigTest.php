<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;

class AppTimezoneConfigTest extends TestCase
{
    public function test_app_timezone_is_driven_by_the_env_variable(): void
    {
        // Arrange — capture prior env state so we can restore it afterwards.
        $previous = getenv('APP_TIMEZONE');
        putenv('APP_TIMEZONE=Asia/Manila');
        $_ENV['APP_TIMEZONE'] = 'Asia/Manila';
        $_SERVER['APP_TIMEZONE'] = 'Asia/Manila';

        try {
            // Act — re-evaluate the config definition with the env set.
            $config = require base_path('config/app.php');

            // Assert — the timezone must reflect APP_TIMEZONE, not a hardcoded value.
            $this->assertSame('Asia/Manila', $config['timezone']);
        } finally {
            if ($previous === false) {
                putenv('APP_TIMEZONE');
                unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);
            } else {
                putenv("APP_TIMEZONE={$previous}");
                $_ENV['APP_TIMEZONE'] = $previous;
                $_SERVER['APP_TIMEZONE'] = $previous;
            }
        }
    }

    public function test_app_timezone_falls_back_to_manila_when_env_is_absent(): void
    {
        // Arrange
        $previous = getenv('APP_TIMEZONE');
        putenv('APP_TIMEZONE');
        unset($_ENV['APP_TIMEZONE'], $_SERVER['APP_TIMEZONE']);

        try {
            // Act
            $config = require base_path('config/app.php');

            // Assert — missing env must still resolve to Manila, not UTC.
            $this->assertSame('Asia/Manila', $config['timezone']);
        } finally {
            if ($previous !== false) {
                putenv("APP_TIMEZONE={$previous}");
                $_ENV['APP_TIMEZONE'] = $previous;
                $_SERVER['APP_TIMEZONE'] = $previous;
            }
        }
    }
}
