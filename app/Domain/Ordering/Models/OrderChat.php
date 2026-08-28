<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Percakapan antara penumpang dan driver dalam satu order.
 *
 * Pesan template dipisah dari pesan bebas, karena hanya yang bebas perlu
 * moderasi. Pesan template ("Saya sudah di depan") juga bisa diterjemahkan di
 * sisi aplikasi tanpa memanggil layanan terjemahan.
 */
class OrderChat extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'sender_type',
        'sender_id',
        'message',
        'is_template',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /**
     * Pesan yang dikirim pihak selain yang sedang membaca.
     *
     * Dipakai menghitung lencana pesan belum dibaca. Pesan sendiri jelas tidak
     * boleh ikut dihitung.
     */
    public function scopeFromOther(Builder $query, string $readerType): Builder
    {
        return $query->where('sender_type', '!=', $readerType);
    }

    public function isFromDriver(): bool
    {
        return $this->sender_type === 'driver';
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
