<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->hasRole('admin') ?? false;

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'middle_name' => $this->middle_name,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'birthday' => $this->birthday?->toDateString(),
            'gender' => $this->gender,
            'civil_status' => $this->civil_status,
            'profile_photo_path' => $this->profile_photo_path,
            'phone' => $this->phone,
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
            'address_line' => $this->address_line,
            'city' => $this->city,
            'province' => $this->province,
            'zip_code' => $this->zip_code,
            'position' => $this->position,
            'employment_type' => $this->employment_type,
            'date_hired' => $this->date_hired?->toDateString(),
            'daily_rate' => $this->when($isAdmin, fn () => $this->daily_rate),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'roles' => $this->whenLoaded('roles', fn () => $this->getRoleNames()),
            'branches' => $this->whenLoaded('branches', fn () => $this->branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
            ])),
            'sss_number' => $this->when($isAdmin, fn () => $this->sss_number),
            'pagibig_number' => $this->when($isAdmin, fn () => $this->pagibig_number),
            'philhealth_number' => $this->when($isAdmin, fn () => $this->philhealth_number),
            'tin_number' => $this->when($isAdmin, fn () => $this->tin_number),
        ];
    }
}
