<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\ServiceType;
use App\Domain\Identity\Models\User;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 *
 * ============================================================================
 *  ANGKA UANGNYA DIHITUNG, BUKAN DITULIS
 * ============================================================================
 *  Tabel orders punya dua constraint yang menolak angka yang tidak konsisten:
 *
 *    orders_breakdown_sums_check   rincian harus menjumlah tepat ke total_fare
 *    orders_split_check            bagian driver + komisi <= yang dibayar
 *
 *  Keduanya benar, dan keduanya akan menolak factory yang nominalnya ditulis
 *  manual sebagai angka yang "kelihatan wajar". Karena itu seluruh nominal di
 *  sini diturunkan dari jarak dan tarif dengan rumus yang sama seperti
 *  FareCalculator, jadi berapa pun jarak yang diminta test, hasilnya tetap
 *  konsisten.
 *
 *  Ini juga yang membuat factory tetap benar saat rumus tarif berubah: yang
 *  perlu diperbaiki satu tempat, bukan setiap test yang pernah menyebut angka.
 * ============================================================================
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** Tarif uji, sengaja bulat supaya angka di pesan kegagalan test mudah dibaca. */
    private const BASE_FARE = 4_000;

    private const PER_KM = 2_500;

    private const PLATFORM_FEE = 1_000;

    private const COMMISSION_PERCENT = 15;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $distanceM = fake()->numberBetween(1_500, 12_000);

        return [
            'order_number' => 'RD-'.now()->format('Ymd').'-'
                .str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),

            'user_id' => User::factory(),
            'service_type_id' => fn (): int => $this->serviceTypeId(),

            'status' => 'searching',
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',

            'pickup_address' => 'Jl. Putri Hijau No. 1, Medan',
            'pickup_lat' => 3.5952,
            'pickup_lng' => 98.6722,

            'dest_address' => 'Sun Plaza, Jl. Zainul Arifin, Medan',
            'dest_lat' => 3.5833,
            'dest_lng' => 98.6742,

            'requested_at' => now(),
        ] + $this->money($distanceM);
    }

    /**
     * Seluruh nominal untuk satu jarak, dijamin konsisten dengan constraint.
     *
     * @return array<string, int>
     */
    private function money(int $distanceM): array
    {
        $distanceFare = (int) floor(($distanceM / 1000) * self::PER_KM);
        $transport = self::BASE_FARE + $distanceFare;

        // Komisi dihitung dari ongkos transport, bukan dari total. Biaya
        // aplikasi milik platform sepenuhnya dan tidak dibagi lagi.
        $commission = (int) floor($transport * self::COMMISSION_PERCENT / 100);
        $driverEarning = $transport - $commission;

        $total = $transport + self::PLATFORM_FEE;

        return [
            'distance_m' => $distanceM,
            'duration_s' => (int) round($distanceM / 1000 * 180),

            'base_fare' => self::BASE_FARE,
            'distance_fare' => $distanceFare,
            'time_fare' => 0,
            'surge_multiplier' => '1.00',
            'surge_amount' => 0,
            'regulatory_adjustment' => 0,
            'platform_fee' => self::PLATFORM_FEE,
            'service_fee' => 0,
            'discount_amount' => 0,

            'total_fare' => $total,
            'driver_earning' => $driverEarning,
            'commission_amount' => $commission,
        ];
    }

    /**
     * Layanan untuk order ini, dibuat kalau belum ada.
     *
     * Katalog TIDAK di-seed di database test — RefreshDatabase mengosongkan
     * seluruh tabel setiap test. Versi pertama method ini mengembalikan hasil
     * `value('id')` apa adanya, yang menjadi 0 saat tabelnya kosong, dan setiap
     * test gagal dengan pelanggaran foreign key yang tidak menyebut penyebab
     * sebenarnya.
     *
     * Membuatnya sendiri di sini yang benar: factory harus bisa dipakai tanpa
     * test perlu tahu bahwa ada katalog yang harus disiapkan lebih dulu.
     */
    private function serviceTypeId(): int
    {
        $existing = ServiceType::query()->where('code', 'ride_bike')->value('id')
            ?? ServiceType::query()->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) ServiceType::factory()->create()->getKey();
    }

    // -------------------------------------------------------------------------
    //  State
    // -------------------------------------------------------------------------

    public function forDistance(int $meters): static
    {
        return $this->state(fn (): array => $this->money($meters));
    }

    public function wallet(): static
    {
        return $this->state(fn (): array => [
            'payment_method' => 'wallet',
            'payment_status' => 'held',
        ]);
    }

    /**
     * Order yang sudah diterima driver.
     *
     * driver_id WAJIB diisi pemanggil, dan itu disengaja: membuat driver di
     * dalam state ini akan menyembunyikan driver mana yang dipakai dari test
     * yang membacanya, sementara hampir setiap test tentang order yang
     * diterima perlu memegang drivernya untuk memeriksa sesuatu.
     */
    public function accepted(int $driverId): static
    {
        return $this->state(fn (): array => [
            'status' => 'accepted',
            'driver_id' => $driverId,
            'matched_at' => now(),
        ]);
    }

    public function inProgress(int $driverId): static
    {
        return $this->state(fn (): array => [
            'status' => 'in_progress',
            'driver_id' => $driverId,
            'matched_at' => now()->subMinutes(8),
            'arrived_at' => now()->subMinutes(4),
            'started_at' => now()->subMinutes(3),
        ]);
    }

    public function completed(int $driverId): static
    {
        return $this->state(fn (): array => [
            'status' => 'completed',
            'driver_id' => $driverId,
            'matched_at' => now()->subMinutes(30),
            'arrived_at' => now()->subMinutes(26),
            'started_at' => now()->subMinutes(25),
            'completed_at' => now()->subMinutes(2),
        ]);
    }

    public function cancelled(string $by = 'user'): static
    {
        return $this->state(fn (): array => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $by,
        ]);
    }

    public function noDriver(): static
    {
        return $this->state(fn (): array => ['status' => 'no_driver']);
    }
}
