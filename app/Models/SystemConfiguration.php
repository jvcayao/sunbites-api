<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemConfiguration extends Model
{
    protected $fillable = ['key', 'value', 'type', 'label', 'description'];

    /**
     * Read a configuration value from the database, cast to the correct type,
     * and cache indefinitely. The cache entry is invalidated on update.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_config.{$key}", function () use ($key, $default) {
            $record = static::where('key', $key)->first();

            if (! $record) {
                return $default;
            }

            return match ($record->type) {
                'integer' => (int) $record->value,
                'decimal' => (float) $record->value,
                default => $record->value,
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
