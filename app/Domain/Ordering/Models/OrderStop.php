<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Shared\ValueObjects\Coordinate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perhentian dalam order multi-drop.
 *
 * Urutannya ditentukan kolom `sequence` dan TIDAK dioptimalkan sistem. Yang
 * dipakai adalah urutan yang diminta pengguna, karena pengguna sering punya
 * alasan yang tidak diketahui sistem (paket pertama harus sampai sebelum jam
 * tutup kantor).
 */
class OrderStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'sequence',
        'type',
        'address',
        'lat',
        'lng',
        'contact_name',
        'contact_phone',
        'note',
        'arrived_at',
        'completed_at',
        'proof_photo_path',
        'receiver_name',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function coordinate(): Coordinate
    {
        return Coordinate::of($this->lat, $this->lng);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isDropoff(): bool
    {
        return $this->type === 'dropoff';
    }

    /**
     * Apakah perhentian ini butuh bukti foto sebelum bisa diselesaikan.
     *
     * Hanya dropoff. Bukti foto pada titik jemput tidak membuktikan apa pun.
     */
    public function requiresProofPhoto(): bool
    {
        return $this->isDropoff();
    }
}
