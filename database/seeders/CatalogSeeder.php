<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Layanan, zona operasional Medan, dan tarif awal.
 *
 * Zona sengaja dibuat sedikit dan kecil. Blueprint bagian 10 menegaskan yang
 * membunuh proyek seperti ini bukan kesulitan teknis, tapi habisnya modal
 * sebelum ada likuiditas dua sisi di satu area kecil. Membuka sepuluh zona
 * sekaligus berarti driver tersebar terlalu tipis di semuanya, dan waktu tunggu
 * di setiap zona jadi buruk.
 *
 * Tarif di sini angka contoh yang masuk akal untuk Medan 2026, BUKAN hasil
 * riset pasar. Yang lebih penting: kolom min_fare_regulated dan
 * max_fare_regulated wajib diisi dengan batas Kementerian Perhubungan yang
 * berlaku sebelum go-live, dan itu perlu dikonfirmasi ke sumber resminya.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $services = $this->createServiceTypes();
            $zones = $this->createZones();
            $this->createPricingRules($services, $zones);
            $this->createSurgeRules($services, $zones);
        });

        $this->command->info(sprintf(
            '  Katalog: %d layanan, %d zona, %d aturan tarif.',
            DB::table('service_types')->count(),
            DB::table('zones')->count(),
            DB::table('pricing_rules')->count(),
        ));
    }

    // -------------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function createServiceTypes(): array
    {
        $definitions = [
            [
                'code' => 'send',
                'name' => 'AntarExpress',
                'description' => 'Kirim paket atau dokumen dalam kota',
                'vehicle_class' => 'motorcycle',
                'sort_order' => 1,
                'requires_multi_stop' => true,
                'requires_proof_photo' => true,
                'max_stops' => 5,
                'max_weight_gram' => 20000,
                'is_active' => true,
            ],
            [
                'code' => 'ride_bike',
                'name' => 'Antaride',
                'description' => 'Perjalanan dengan sepeda motor',
                'vehicle_class' => 'motorcycle',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'ride_car',
                'name' => 'Antar Mobil',
                'description' => 'Perjalanan dengan mobil',
                'vehicle_class' => 'car_economy',
                'sort_order' => 3,
                // Belum diaktifkan. Blueprint bagian 10 menyarankan satu
                // vertikal dulu sampai punya cukup driver.
                'is_active' => false,
            ],
            [
                'code' => 'food',
                'name' => 'Pesan Makanan',
                'description' => 'Pesan dari restoran dan warung terdaftar',
                'vehicle_class' => 'motorcycle',
                'sort_order' => 4,
                'requires_merchant' => true,
                'is_active' => false,
            ],
        ];

        $ids = [];

        foreach ($definitions as $definition) {
            $ids[$definition['code']] = DB::table('service_types')->insertGetId(
                array_merge([
                    'requires_merchant' => false,
                    'requires_multi_stop' => false,
                    'requires_proof_photo' => false,
                    'max_stops' => 1,
                    'max_weight_gram' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $definition),
            );
        }

        return $ids;
    }

    // -------------------------------------------------------------------------

    /**
     * Zona operasional. Fase 1 hanya Medan Kota dan sekitarnya.
     *
     * @return array<string, int>
     */
    private function createZones(): array
    {
        $definitions = [
            [
                'code' => 'MDN-KOTA',
                'name' => 'Medan Kota',
                'city' => 'Medan',
                'province' => 'Sumatera Utara',
                'priority' => 10,
                // Cincin polygon, urutan lng,lat sesuai GeoJSON. Titik pertama
                // dan terakhir HARUS sama, itu syarat GeoJSON Polygon.
                'ring' => [
                    [98.6550, 3.5680],
                    [98.7000, 3.5680],
                    [98.7000, 3.6050],
                    [98.6550, 3.6050],
                    [98.6550, 3.5680],
                ],
            ],
            [
                'code' => 'MDN-BARU',
                'name' => 'Medan Baru & Petisah',
                'city' => 'Medan',
                'province' => 'Sumatera Utara',
                'priority' => 10,
                'ring' => [
                    [98.6450, 3.5850],
                    [98.6760, 3.5850],
                    [98.6760, 3.6180],
                    [98.6450, 3.6180],
                    [98.6450, 3.5850],
                ],
            ],
            [
                'code' => 'MDN-TIMUR',
                'name' => 'Medan Timur & Perjuangan',
                'city' => 'Medan',
                'province' => 'Sumatera Utara',
                'priority' => 10,
                'ring' => [
                    [98.6820, 3.5950],
                    [98.7200, 3.5950],
                    [98.7200, 3.6300],
                    [98.6820, 3.6300],
                    [98.6820, 3.5950],
                ],
            ],
        ];

        $ids = [];

        foreach ($definitions as $definition) {
            $ring = $definition['ring'];
            $bounds = $this->boundsOf($ring);

            $ids[$definition['code']] = DB::table('zones')->insertGetId([
                'uuid' => (string) Str::uuid7(),
                'name' => $definition['name'],
                'code' => $definition['code'],
                'city' => $definition['city'],
                'province' => $definition['province'],
                'priority' => $definition['priority'],
                'polygon_geojson' => json_encode([
                    'type' => 'Polygon',
                    'coordinates' => [$ring],
                ], JSON_THROW_ON_ERROR),
                'min_lat' => $bounds['min_lat'],
                'max_lat' => $bounds['max_lat'],
                'min_lng' => $bounds['min_lng'],
                'max_lng' => $bounds['max_lng'],
                'center_lat' => $bounds['center_lat'],
                'center_lng' => $bounds['center_lng'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    /**
     * Bounding box dan titik pusat dihitung dari polygon, bukan diisi manual.
     *
     * Diisi manual berarti suatu hari polygon diubah lewat panel dan bbox-nya
     * tertinggal. Konsekuensinya halus dan sulit dilacak: resolver zona akan
     * membuang titik yang sebenarnya masuk zona, karena filter bbox murahnya
     * menolak lebih dulu, dan order yang seharusnya bisa dilayani ditolak
     * dengan alasan di luar area.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array<string, float>
     */
    private function boundsOf(array $ring): array
    {
        $lngs = array_column($ring, 0);
        $lats = array_column($ring, 1);

        return [
            'min_lat' => min($lats),
            'max_lat' => max($lats),
            'min_lng' => min($lngs),
            'max_lng' => max($lngs),
            'center_lat' => round((min($lats) + max($lats)) / 2, 7),
            'center_lng' => round((min($lngs) + max($lngs)) / 2, 7),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * @param  array<string, int>  $services
     * @param  array<string, int>  $zones
     */
    private function createPricingRules(array $services, array $zones): void
    {
        // Tarif default berlaku untuk zona mana pun yang tidak punya tarif
        // khusus. zone_id NULL yang menandainya.
        $defaults = [
            // Sedikit di atas ride_bike, karena ada penanganan barang dan bukti
            // pengantaran yang menambah waktu driver di luar perjalanan.
            'send' => [
                'base_fare' => 5_000,
                'per_km' => 1_800,
                'per_minute' => 100,
                'minimum_fare' => 10_000,
                'free_distance_m' => 2_000,
                'platform_fee' => 1_000,
                'commission_percent' => 15.00,
                'insurance_fee' => 500,
            ],
            // Dikalibrasi dengan `php artisan antaride:simulate-fare` supaya
            // ongkos 8 km jatuh di kisaran Rp 16.000, mendekati harga pasar
            // Medan. Angka pertama yang saya isi menghasilkan Rp 23.650 untuk
            // jarak itu, dan simulatorlah yang menunjukkannya.
            //
            // Ini TETAP angka contoh, bukan hasil riset pasar. Sebelum go-live,
            // jalankan simulator dengan angka calon lalu bandingkan dengan tarif
            // Gojek dan Grab pada jarak yang sama di zona yang sama.
            'ride_bike' => [
                'base_fare' => 4_000,
                'per_km' => 1_600,
                'per_minute' => 80,
                'minimum_fare' => 9_000,
                'free_distance_m' => 2_000,
                'platform_fee' => 1_000,
                'commission_percent' => 15.00,
                // Batas Kemenhub. Angka ini WAJIB diperiksa ke sumber resmi
                // sebelum go-live; yang di sini hanya tempat isinya.
                'min_fare_regulated' => 7_000,
                'max_fare_regulated' => 150_000,
            ],
            // Angka pertama yang saya isi (base 12.000, per_km 4.500)
            // menghasilkan Rp 47.900 untuk 8 km, jauh di atas harga pasar.
            // Diturunkan ke kisaran Rp 31.000, mendekati GrabCar di Medan.
            'ride_car' => [
                'base_fare' => 8_000,
                'per_km' => 3_000,
                'per_minute' => 150,
                'minimum_fare' => 18_000,
                'free_distance_m' => 1_000,
                'platform_fee' => 2_000,
                'commission_percent' => 18.00,
                'min_fare_regulated' => 15_000,
                'max_fare_regulated' => 400_000,
            ],
            // Ongkos kirim makanan, di atas ride_bike karena driver menunggu
            // pesanan disiapkan. Waktu tunggu itu tidak masuk durasi rute, jadi
            // dikompensasi lewat tarif buka pintu yang lebih tinggi.
            'food' => [
                'base_fare' => 6_000,
                'per_km' => 1_700,
                'per_minute' => 100,
                'minimum_fare' => 9_000,
                'free_distance_m' => 1_500,
                'platform_fee' => 1_500,
                'commission_percent' => 15.00,
                'packaging_fee' => 1_000,
            ],
        ];

        foreach ($defaults as $code => $components) {
            DB::table('pricing_rules')->insert(array_merge([
                'uuid' => (string) Str::uuid7(),
                'service_type_id' => $services[$code],
                'zone_id' => null,
                'per_minute' => 0,
                'free_distance_m' => 0,
                'platform_fee' => 0,
                'commission_percent' => 0,
                'min_fare_regulated' => null,
                'max_fare_regulated' => null,
                'packaging_fee' => 0,
                'insurance_fee' => 0,
                // Dimulai dari kemarin supaya order yang dibuat sekarang pasti
                // menemukan tarif yang berlaku, tanpa bergantung pada jam
                // seeder dijalankan.
                'effective_from' => now()->subDay()->startOfDay(),
                'effective_until' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], $components));
        }

        // Tarif khusus zona pusat kota: jarak tempuh lebih pendek tapi lalu
        // lintas lebih padat, jadi komponen waktu dinaikkan dan komponen jarak
        // diturunkan sedikit.
        DB::table('pricing_rules')->insert([
            'uuid' => (string) Str::uuid7(),
            'service_type_id' => $services['ride_bike'],
            'zone_id' => $zones['MDN-KOTA'],
            // Per km lebih rendah, per menit lebih tinggi: di pusat kota jarak
            // tempuhnya pendek tapi waktunya panjang karena padat.
            //
            // Angka pertama yang saya isi (per_km 2.000, per_menit 250) justru
            // membuat tarif pusat kota LEBIH MAHAL dari tarif default pada 8 km,
            // yang berlawanan dengan maksudnya. Simulator yang menemukannya.
            'base_fare' => 4_000,
            'per_km' => 1_400,
            'per_minute' => 130,
            'minimum_fare' => 9_000,
            'free_distance_m' => 1_500,
            'platform_fee' => 1_000,
            'commission_percent' => 15.00,
            'min_fare_regulated' => 7_000,
            'max_fare_regulated' => 150_000,
            'packaging_fee' => 0,
            'insurance_fee' => 0,
            'effective_from' => now()->subDay()->startOfDay(),
            'effective_until' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Surge berjadwal untuk jam sibuk.
     *
     * Sengaja konservatif: 1.3x di jam pulang kerja, bukan 2x. Surge tinggi di
     * fase awal membuat pengguna baru mencoba sekali lalu tidak kembali, dan
     * yang dibutuhkan di Fase 1 adalah kebiasaan, bukan margin.
     *
     * @param  array<string, int>  $services
     * @param  array<string, int>  $zones
     */
    private function createSurgeRules(array $services, array $zones): void
    {
        foreach ($zones as $zoneId) {
            // Jam pulang kerja, Senin sampai Jumat.
            foreach ([1, 2, 3, 4, 5] as $dayOfWeek) {
                DB::table('surge_rules')->insert([
                    'uuid' => (string) Str::uuid7(),
                    'zone_id' => $zoneId,
                    'service_type_id' => $services['ride_bike'],
                    'trigger_type' => 'schedule',
                    'day_of_week' => $dayOfWeek,
                    'start_time' => '17:00:00',
                    'end_time' => '19:30:00',
                    'multiplier' => 1.30,
                    'demand_threshold' => null,
                    'manual_until' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Surge otomatis berbasis rasio permintaan, aktif kapan saja.
            // Menyala kalau order berbanding driver tersedia melewati 2.5.
            DB::table('surge_rules')->insert([
                'uuid' => (string) Str::uuid7(),
                'zone_id' => $zoneId,
                'service_type_id' => $services['ride_bike'],
                'trigger_type' => 'demand_ratio',
                'day_of_week' => null,
                'start_time' => null,
                'end_time' => null,
                'multiplier' => 1.50,
                'demand_threshold' => 2.50,
                'manual_until' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
