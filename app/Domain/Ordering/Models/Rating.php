<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Identity\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penilaian setelah order selesai.
 *
 * Satu pihak hanya boleh menilai satu kali per order, ditegakkan unique
 * constraint `(order_id, rater_type)`. Penumpang dan driver masing-masing dapat
 * satu kesempatan.
 *
 * Komentar yang melanggar DISEMBUNYIKAN, tidak dihapus. Keputusan moderasi
 * harus bisa ditinjau, dan komentar yang hilang tanpa jejak membuat driver yang
 * dituduh tidak bisa membela diri.
 */
class Rating extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'rater_type',
        'rater_id',
        'ratee_type',
        'ratee_id',
        'score',
        'tags',
        'comment',
        'is_hidden',
        'hidden_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'tags' => 'array',
            'is_hidden' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'hidden_by_admin_id');
    }

    // -------------------------------------------------------------------------

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function scopeForRatee(Builder $query, string $type, int $id): Builder
    {
        return $query
            ->where('ratee_type', $type)
            ->where('ratee_id', $id)
            ->where('is_hidden', false);
    }

    /**
     * Rating rendah yang perlu ditindak tim ops.
     *
     * Ambangnya dua bintang atau kurang. Tiga bintang adalah "biasa saja" dan
     * tidak menandakan masalah; menindaknya akan membuat driver dihukum karena
     * penumpang yang memang pelit bintang.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where('score', '<=', 2)->where('is_hidden', false);
    }

    public function isLow(): bool
    {
        return $this->score <= 2;
    }

    public function isFromPassenger(): bool
    {
        return $this->rater_type === 'user';
    }
}
