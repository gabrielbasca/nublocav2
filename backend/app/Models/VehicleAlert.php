<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleAlert extends Model
{
    public const REASON_BLOCKING_ACCESS = 'blocking_access';

    public const REASON_LIGHTS_ON = 'lights_on';

    public const REASON_WINDOW_OPEN = 'window_open';

    public const REASON_ACCIDENT_DAMAGE = 'accident_damage';

    public const REASON_OTHER = 'other';

    public const STATUS_NEW = 'new';

    public const STATUS_READ = 'read';

    /**
     * @return list<string>
     */
    public static function reasons(): array
    {
        return [
            self::REASON_BLOCKING_ACCESS,
            self::REASON_LIGHTS_ON,
            self::REASON_WINDOW_OPEN,
            self::REASON_ACCIDENT_DAMAGE,
            self::REASON_OTHER,
        ];
    }

    protected $fillable = [
        'vehicle_id',
        'reason',
        'status',
        'read_at',
        'reporter_ip_hash',
        'reporter_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function markRead(): void
    {
        if ($this->status !== self::STATUS_READ) {
            $this->forceFill([
                'status' => self::STATUS_READ,
                'read_at' => $this->read_at ?? now(),
            ])->save();
        }
    }
}
