<?php

namespace App\Models;

use App\Enums\GrammaticalGender;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property GrammaticalGender|null $grammatical_gender
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'grammatical_gender', 'email', 'password', 'role'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'grammatical_gender' => GrammaticalGender::class,
        ];
    }

    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'responsible_user_id');
    }

    /** Zadaci (stavke predmeta) za koje je korisnik odgovoran. */
    public function assignedItems(): HasMany
    {
        return $this->hasMany(AdministrativeCaseItem::class, 'responsible_user_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('role')->withTimestamps();
    }

    public function isAdministrator(): bool
    {
        return $this->role === UserRole::Administrator;
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(UserNotificationSetting::class);
    }

    public function notificationSettingFor(): UserNotificationSetting
    {
        return $this->notificationSetting()->firstOrCreate(['user_id' => $this->id]);
    }

    /** Kratko ime za tabele i liste: "Ensar Mešić" → "Ensar M." */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : $this->name;
    }

    /** Glagol u rodu korisnika: verb('promijenio') → "promijenio" / "promijenila" / "promijenio/la". */
    public function verb(string $masculine): string
    {
        return GrammaticalGender::verb($this->grammatical_gender, $masculine);
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
