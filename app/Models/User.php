<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $username
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int|null $role_id
 * @property int|null $supervisor_user_id
 * @property string|null $phone
 * @property string|null $avatar
 * @property string $status
 * @property int $session_version
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'username',
    'email',
    'password',
    'role_id',
    'supervisor_user_id',
    'phone',
    'avatar',
    'preferences',
    'status',
    'session_version',
    'last_login_at',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $attributes = [
        'status' => UserStatus::Active->value,
        'session_version' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'session_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_user_id');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function teamMembers(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_user_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function assignedProperties(): HasMany
    {
        return $this->hasMany(Property::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<FollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'advisor_user_id');
    }

    /**
     * @return HasMany<Commission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'advisor_user_id');
    }

    /**
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * @return HasMany<CrmNotification, $this>
     */
    public function crmNotifications(): HasMany
    {
        return $this->hasMany(CrmNotification::class);
    }

    public function isAdministrador(): bool
    {
        return $this->roleSlug() === UserRole::Administrador->value;
    }

    public function isSupervisor(): bool
    {
        return $this->roleSlug() === UserRole::Supervisor->value;
    }

    public function isAsesor(): bool
    {
        return $this->roleSlug() === UserRole::Asesor->value || $this->roleSlug() === null;
    }

    public function roleSlug(): ?string
    {
        if ($this->relationLoaded('role')) {
            return $this->role instanceof Role ? $this->role->slug : null;
        }

        return $this->role()->value('slug');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isAdministrador()) {
            return true;
        }

        if ($this->relationLoaded('role') && $this->role instanceof Role && $this->role->relationLoaded('permissions')) {
            return $this->role->permissions->contains('slug', $permission);
        }

        return $this->role()
            ->whereHas('permissions', fn ($query) => $query->where('slug', $permission))
            ->exists();
    }

    public function canAccessAssigned(?int $assignedUserId): bool
    {
        if ($this->isAdministrador()) {
            return true;
        }

        if ($assignedUserId === null || $assignedUserId <= 0) {
            return false;
        }

        if ($this->isSupervisor()) {
            return $assignedUserId === $this->getKey()
                || self::query()
                    ->whereKey($assignedUserId)
                    ->where('supervisor_user_id', $this->getKey())
                    ->where('status', UserStatus::Active->value)
                    ->exists();
        }

        return $assignedUserId === $this->getKey();
    }

    public function canAssignTo(?int $targetUserId): bool
    {
        if ($targetUserId === null || $targetUserId <= 0) {
            return true;
        }

        return $this->canAccessAssigned($targetUserId);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
