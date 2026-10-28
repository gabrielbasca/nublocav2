<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Vehicle extends Model
{
    protected $appends = ['public_url'];

    protected $fillable = [
        'user_id',
        'license_plate',
    ];

    public static function normalizeLicensePlate(string $licensePlate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($licensePlate))) ?? '';
    }

    public function setLicensePlateAttribute(string $licensePlate): void
    {
        $this->attributes['license_plate'] = self::normalizeLicensePlate($licensePlate);
    }

    protected static function booted(): void
    {
        static::creating(function (Vehicle $vehicle): void {
            if (! $vehicle->public_token) {
                $vehicle->public_token = self::generatePublicToken();
            }
        });
    }

    public static function generatePublicToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function getPublicUrlAttribute(): string
    {
        return rtrim((string) config('services.nubloca.public_vehicle_url_prefix'), '/')
            .'/'.$this->public_token;
    }

    public function rotatePublicToken(): void
    {
        $this->forceFill(['public_token' => self::generatePublicToken()])->save();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
