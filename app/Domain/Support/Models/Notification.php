<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Shared\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Notifikasi in-app untuk penumpang, driver, atau merchant.
 *
 * Penjelasan lengkap kenapa tabelnya bukan `notifications` bawaan Laravel, dan
 * kenapa admin TIDAK termasuk penerimanya, ada di migration-nya.
 */
class Notification extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'type',
        'title',
        'body',
        'action',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => 'array',
            'read_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    //  Jenis notifikasi
    // -------------------------------------------------------------------------
    //
    //  Konstanta, bukan enum PHP. Alasannya: daftar ini akan bertambah seiring
    //  fitur, dan enum yang ketat berarti baris lama dengan jenis yang sudah
    //  dihapus dari enum akan MELEMPAR saat dibaca — pada data historis yang
    //  tidak salah apa pun.
    //
    //  Aplikasi juga memperlakukan jenis yang tidak dikenal sebagai notifikasi
    //  biasa dengan ikon bawaan, bukan sebagai galat. Itu yang membuat backend
    //  bisa menambah jenis baru tanpa menunggu semua pengguna memperbarui
    //  aplikasinya.
    //
    public const ORDER_ACCEPTED = 'order.accepted';

    public const ORDER_DRIVER_ARRIVED = 'order.driver_arrived';

    public const ORDER_STARTED = 'order.started';

    public const ORDER_COMPLETED = 'order.completed';

    public const ORDER_CANCELLED = 'order.cancelled';

    public const ORDER_NO_DRIVER = 'order.no_driver';

    public const DRIVER_ORDER_ASSIGNED = 'driver.order_assigned';

    public const DRIVER_ORDER_CANCELLED = 'driver.order_cancelled';

    public const WALLET_CREDITED = 'wallet.credited';

    public const ANNOUNCEMENT = 'announcement';

    // -------------------------------------------------------------------------

    public function scopeForRecipient(
        Builder $query,
        string $type,
        int $id,
    ): Builder {
        return $query
            ->where('recipient_type', $type)
            ->where('recipient_id', $id);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Tandai sudah dibaca.
     *
     * Idempoten: notifikasi yang sudah dibaca TIDAK diperbarui lagi. Tanpa
     * pemeriksaan ini, membuka daftar notifikasi berulang akan menulis ulang
     * `read_at` setiap kali — dan waktu baca pertama, yang justru berguna untuk
     * memahami perilaku pengguna, hilang.
     */
    public function markRead(): bool
    {
        if ($this->read_at !== null) {
            return false;
        }

        $this->read_at = now();

        return $this->save();
    }
}
