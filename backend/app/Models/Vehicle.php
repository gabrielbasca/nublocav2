<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Vehicle extends Model
{
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
