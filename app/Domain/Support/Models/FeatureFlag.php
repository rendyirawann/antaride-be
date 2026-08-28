<?php

declare(strict_types=1);

namespace App\Domain\Support\Models;

use App\Domain\Identity\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Feature flag dan kill switch.
 *
 * ============================================================================
 *  KENAPA MODEL INI ADA, PADAHAL TABELNYA SUDAH DIPAKAI
 * ============================================================================
 *  Tiga tempat membaca tabel ini dengan pola yang sama persis, dan
 *  masing-masing menulis ulang polanya sendiri:
 *
 *    ResolveSurge::surgeEnabled()
 *    FindEligiblePromos::promoEnabled()
 *    Withdrawal::autoApprovalEnabled()
 *
 *  Ketiganya melakukan `cache()->remember()` dengan nama kunci yang disusun
 *  sendiri, TTL yang ditulis sendiri, dan nilai default yang berbeda-beda.
 *  Konsekuensinya sudah mulai terlihat: nilai default untuk flag yang hilang
 *  berbeda antar tempat, dan tidak ada satu pun tempat yang bisa membatalkan
 *  cache seluruh flag setelah panel admin mengubahnya.
 *
 *  Yang paling berbahaya adalah yang terakhir. Kill switch yang diubah di panel
 *  tapi masih ter-cache 30 detik di tiga tempat berbeda berarti tim ops menekan
 *  tombol saat ada insiden dan tidak melihat apa pun berubah — lalu menekannya
 *  lagi, dan lagi.
 *
 *  Model ini menyatukannya: satu pola cache, satu tempat membatalkannya.
 * ============================================================================
 *
 * ============================================================================
 *  NILAI DEFAULT DITENTUKAN PEMANGGIL, DAN ITU DISENGAJA
 * ============================================================================
 *  `isEnabled($key, $default)` menuntut pemanggil memutuskan apa yang terjadi
 *  kalau flag-nya tidak ada. Tidak ada default global, karena arah yang aman
 *  berbeda per flag:
 *
 *    surge.enabled              default TRUE — surge yang hilang hanya
 *                               mengurangi pendapatan
 *    withdrawal.auto_approve    default FALSE — yang hilang adalah kontrol atas
 *                               uang keluar
 *
 *  Default global apa pun akan salah untuk separuh flag.
 * ============================================================================
 */
class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'description',
        'is_enabled',
        'zone_ids',
        'rollout_percent',
        'auto_revert_at',
        'updated_by_admin_id',
        'last_change_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'zone_ids' => 'array',
            'rollout_percent' => 'integer',
            'auto_revert_at' => 'datetime',
        ];
    }

    /** Cache singkat: cukup untuk tidak jadi query per request, cukup pendek untuk terasa langsung saat diubah. */
    private const CACHE_TTL_SECONDS = 30;

    // -------------------------------------------------------------------------

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    // -------------------------------------------------------------------------

    /**
     * Apakah sebuah flag menyala.
     *
     * @param  bool  $default  yang berlaku kalau flag-nya tidak ada di database
     */
    public static function isEnabled(string $key, bool $default = false): bool
    {
        return (bool) Cache::remember(
            self::cacheKey($key),
            now()->addSeconds(self::CACHE_TTL_SECONDS),

            /*
             * Query builder, bukan Eloquent.
             *
             * Method ini dipanggil dari jalur terpanas di sistem — setiap quote
             * membacanya. Membangun model Eloquent untuk mengambil satu boolean
             * adalah biaya yang tidak menghasilkan apa pun.
             */
            static fn () => DB::table('feature_flags')
                ->where('key', $key)
                ->value('is_enabled') ?? $default,
        );
    }

    /**
     * Ubah sebuah flag, lalu batalkan cache-nya SEKARANG.
     *
     * ========================================================================
     *  PEMBATALAN CACHE ADALAH SELURUH ALASAN METHOD INI ADA
     * ========================================================================
     *  Tanpa `Cache::forget`, kill switch yang ditekan saat ada insiden tidak
     *  berefek sampai 30 detik kemudian — dan tiga puluh detik saat sedang ada
     *  banjir order adalah waktu yang sangat lama. Yang terjadi di lapangan:
     *  tim ops menekan tombolnya, tidak melihat perubahan, lalu menekannya lagi
     *  beberapa kali, dan akhirnya menyimpulkan panelnya rusak.
     *
     *  Menaruh pembatalan di sini — bukan di controller — berarti tidak ada
     *  jalur pengubahan yang bisa lupa melakukannya.
     * ========================================================================
     */
    public static function set(
        string $key,
        bool $enabled,
        ?int $adminId = null,
        ?string $reason = null,
    ): void {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => $key],
            [
                'is_enabled' => $enabled,
                'updated_by_admin_id' => $adminId,
                'last_change_reason' => $reason,
                'updated_at' => now(),
            ],
        );

        Cache::forget(self::cacheKey($key));
    }

    /**
     * Batalkan cache seluruh flag.
     *
     * Dipakai setelah seeding dan setelah pemulihan database. Tidak dipakai di
     * jalur normal: mengosongkan cache seluruh flag berarti setiap flag dibaca
     * ulang dari database pada request berikutnya, dan pada jam sibuk itu
     * lonjakan query yang tidak perlu.
     */
    public static function flushCache(): void
    {
        foreach (DB::table('feature_flags')->pluck('key') as $key) {
            Cache::forget(self::cacheKey((string) $key));
        }
    }

    private static function cacheKey(string $key): string
    {
        return "feature:{$key}";
    }

    // -------------------------------------------------------------------------

    /**
     * Apakah flag ini akan kembali sendiri.
     *
     * `auto_revert_at` ada untuk perubahan yang dimaksudkan sementara — surge
     * manual saat ada acara, misalnya. Yang paling sering terjadi tanpa fitur
     * ini: flag dimatikan saat insiden, insidennya selesai, dan tidak ada yang
     * ingat menyalakannya lagi sampai ada yang bertanya kenapa pendapatan turun.
     */
    public function willAutoRevert(): bool
    {
        return $this->auto_revert_at !== null && $this->auto_revert_at->isFuture();
    }

    public function isOverdueForRevert(): bool
    {
        return $this->auto_revert_at !== null && $this->auto_revert_at->isPast();
    }
}
