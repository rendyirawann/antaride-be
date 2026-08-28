<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Shared\Concerns\HasUuid;
use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alamat tersimpan milik pengguna: rumah, kantor, dan seterusnya.
 *
 * Satu alamat utama per pengguna ditegakkan partial unique index
 * `user_addresses_one_primary` di database, bukan hanya oleh kode.
 */
class UserAddress extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'user_id',
        'label',
        'address',
        'detail',
        'note',
        'lat',
        'lng',
        'contact_name',
        'contact_phone',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_primary' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function coordinate(): Coordinate
    {
        return Coordinate::of($this->lat, $this->lng);
    }
}
