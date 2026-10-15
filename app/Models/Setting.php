<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Read a setting value (cached for 60s).
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return Cache::remember("setting:{$key}", 60, function () use ($key, $default) {
            $setting = static::where('key', $key)->first();

            return $setting?->value ?? $default;
        });
    }

    /**
     * Write a setting value.
     */
    public static function setValue(string $key, mixed $value): static
    {
        $setting = static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");

        return $setting;
    }
}
