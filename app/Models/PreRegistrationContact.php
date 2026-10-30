<?php

namespace App\Models;

use Database\Factories\PreRegistrationContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pre_registration_id',
    'full_name',
    'relationship',
    'phone',
    'email',
    'address',
    'is_primary',
])]
class PreRegistrationContact extends Model
{
    /** @use HasFactory<PreRegistrationContactFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<PreRegistration, $this> */
    public function preRegistration(): BelongsTo
    {
        return $this->belongsTo(PreRegistration::class);
    }
}
