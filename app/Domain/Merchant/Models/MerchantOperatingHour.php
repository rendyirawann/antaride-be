<?php

declare(strict_types=1);

namespace App\Domain\Merchant\Models;

use App\Domain\Shared\Support\BusinessClock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jam operasional merchant, per hari dalam seminggu.
 */
class MerchantOperatingHour extends Model
{
    use HasFactory;

    protected $table = 'merchant_operating_hours';

    protected $fillable = ['merchant_id', 'day_of_week', 'open_time', 'close_time', 'is_closed'];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Apakah jam ini mencakup satu saat tertentu.
     *
     * Rentang yang melewati tengah malam ditangani: warung yang buka 18:00
     * sampai 02:00 tetap buka jam 1 pagi. Tanpa penanganan ini, warung malam
     * akan tampak tutup pada jam justru paling ramainya, dan tidak ada error
     * apa pun yang muncul untuk menjelaskannya.
     *
     * Perbandingannya dilakukan dalam ZONA BISNIS. Kolom open_time dan
     * close_time menyimpan jam WIB apa adanya, sementara aplikasi berjalan di
     * UTC. Membandingkan langsung pada UTC membuat seluruh jam buka bergeser
     * tujuh jam: warung yang buka 08:00-20:00 WIB akan tampak buka
     * 15:00-03:00 WIB.
     */
    public function covers(\DateTimeInterface $at): bool
    {
        if ($this->is_closed) {
            return false;
        }

        if ($this->open_time === null || $this->close_time === null) {
            return false;
        }

        return BusinessClock::timeWithinRange(
            (string) $this->open_time,
            (string) $this->close_time,
            $at,
        );
    }

    public function dayName(): string
    {
        return match ($this->day_of_week) {
            0 => 'Minggu',
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
            default => 'Tidak diketahui',
        };
    }

    public function displayRange(): string
    {
        if ($this->is_closed) {
            return 'Tutup';
        }

        return substr((string) $this->open_time, 0, 5).' - '.substr((string) $this->close_time, 0, 5);
    }
}
