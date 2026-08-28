<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data sistem yang harus ada sebelum order pertama bisa dibuat.
 *
 * Termasuk hal-hal yang biasanya baru diingat setelah dibutuhkan: kill switch,
 * ambang approval, alasan pembatalan, dan akun lawan transaksi untuk ledger.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->createPlatformWallets();
            $this->createCancellationReasons();
            $this->createTicketCategories();
            $this->createApprovalThresholds();
            $this->createFeatureFlags();
            $this->createSettings();
        });

        $this->command->info(sprintf(
            '  Sistem: %d wallet platform, %d alasan batal, %d feature flag, %d setting.',
            DB::table('wallets')->where('owner_type', 'platform')->count(),
            DB::table('cancellation_reasons')->count(),
            DB::table('feature_flags')->count(),
            DB::table('settings')->count(),
        ));
    }

    // -------------------------------------------------------------------------

    /**
     * Akun platform sebagai lawan transaksi ledger.
     *
     * Ini prasyarat penjaga double-entry di database. Setiap perpindahan uang
     * butuh dua sisi yang berjumlah nol, dan uang yang datang dari atau pergi
     * ke luar sistem butuh akun yang mewakili "luar".
     *
     * Contoh top up Rp 100.000:
     *   DEBIT  platform:settlement  100.000   (uang masuk dari gateway)
     *   CREDIT wallet user          100.000
     *
     * Tanpa akun settlement, baris kedua tidak punya pasangan, dan trigger
     * `wallet_transactions_balanced` akan menolak seluruh transaksi. Itu
     * perilaku yang benar; akun-akun di bawah inilah jawabannya.
     *
     * owner_id di sini adalah konstanta, BUKAN foreign key ke tabel mana pun.
     * Nilainya bagian dari kontrak data: jangan pernah diubah setelah ada baris
     * ledger yang menunjuknya.
     */
    private function createPlatformWallets(): void
    {
        $accounts = [
            1 => 'Pendapatan komisi',
            2 => 'Settlement gateway',
            3 => 'Biaya promo',
            4 => 'Biaya insentif driver',
            5 => 'Biaya refund',
        ];

        foreach ($accounts as $ownerId => $label) {
            DB::table('wallets')->insertOrIgnore([
                'uuid' => (string) Str::uuid7(),
                'owner_type' => 'platform',
                'owner_id' => $ownerId,
                'currency' => 'IDR',
                'balance' => 0,
                'held_balance' => 0,
                'version' => 0,
                'is_frozen' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

    private function createCancellationReasons(): void
    {
        $reasons = [
            // --- Alasan penumpang ---
            ['user', 'CANCEL_DRIVER_TOO_FAR', 'Driver terlalu jauh', false, false],
            ['user', 'CANCEL_WAIT_TOO_LONG', 'Menunggu terlalu lama', false, false],
            ['user', 'CANCEL_CHANGE_PLAN', 'Berubah rencana', true, false],
            ['user', 'CANCEL_WRONG_ADDRESS', 'Salah memasukkan alamat', true, false],
            ['user', 'CANCEL_DRIVER_UNREACHABLE', 'Driver tidak bisa dihubungi', false, false],
            ['user', 'CANCEL_DRIVER_ASKED', 'Driver meminta dibatalkan', false, true],
            ['user', 'CANCEL_OTHER', 'Alasan lain', true, false],

            // --- Alasan driver ---
            // affects_driver_score = true untuk alasan yang menandakan driver
            // membatalkan karena pertimbangannya sendiri. Yang di luar kendali
            // driver (penumpang tidak ada, alamat tidak terjangkau) tidak boleh
            // menurunkan skornya, kalau tidak driver akan berhenti melaporkan
            // masalah nyata dan memilih membiarkan order kadaluarsa.
            ['driver', 'DRV_PASSENGER_ABSENT', 'Penumpang tidak ada di lokasi', false, false],
            ['driver', 'DRV_ADDRESS_UNREACHABLE', 'Alamat tidak bisa dijangkau', false, false],
            ['driver', 'DRV_VEHICLE_PROBLEM', 'Kendaraan bermasalah', false, true],
            ['driver', 'DRV_PASSENGER_RUDE', 'Penumpang tidak sopan', false, false],
            ['driver', 'DRV_TOO_MANY_PASSENGERS', 'Penumpang melebihi kapasitas', false, false],
            ['driver', 'DRV_ITEM_NOT_ALLOWED', 'Barang tidak boleh diangkut', false, false],
            ['driver', 'DRV_OTHER', 'Alasan lain', false, true],

            // --- Alasan admin & sistem ---
            ['admin', 'ADM_FRAUD_SUSPECTED', 'Dugaan kecurangan', false, false],
            ['admin', 'ADM_OPS_DECISION', 'Keputusan operasional', false, false],
            ['system', 'SYS_NO_DRIVER', 'Tidak ada driver tersedia', false, false],
            ['system', 'SYS_PAYMENT_FAILED', 'Pembayaran gagal', false, false],
            ['system', 'SYS_MERCHANT_NO_RESPONSE', 'Merchant tidak merespons', false, false],
            ['system', 'SYS_KILL_SWITCH', 'Layanan dihentikan sementara', false, false],
        ];

        $sortOrder = 0;

        foreach ($reasons as [$actorType, $code, $text, $chargesFee, $affectsScore]) {
            DB::table('cancellation_reasons')->insertOrIgnore([
                'actor_type' => $actorType,
                'code' => $code,
                'text' => $text,
                'charges_fee' => $chargesFee,
                'affects_driver_score' => $affectsScore,
                'is_active' => true,
                'sort_order' => $sortOrder++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

    private function createTicketCategories(): void
    {
        $categories = [
            ['Masalah Ongkos', 'ongkos', 'normal', 240],
            ['Driver Bermasalah', 'driver', 'high', 60],
            ['Barang Rusak atau Hilang', 'barang', 'high', 60],
            ['Saldo dan Pembayaran', 'saldo', 'high', 120],
            ['Penarikan Saldo', 'penarikan', 'high', 120],
            ['Akun dan Login', 'akun', 'normal', 240],
            ['Promo Tidak Berlaku', 'promo', 'low', 480],
            ['Keselamatan', 'keselamatan', 'urgent', 15],
            ['Lainnya', 'lainnya', 'low', 480],
        ];

        $sortOrder = 0;

        foreach ($categories as [$name, $slug, $priority, $sla]) {
            DB::table('ticket_categories')->insertOrIgnore([
                'name' => $name,
                'slug' => $slug,
                'default_priority' => $priority,
                'sla_response_minutes' => $sla,
                'is_active' => true,
                'sort_order' => $sortOrder++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Ambang approval (blueprint admin bagian 7).
     *
     * Rentangnya bersambungan tanpa tumpang tindih, dan itu ditegakkan
     * exclusion constraint di tabelnya. Batas atas eksklusif, jadi 5.000.000
     * masuk ke aturan ketiga, bukan kedua.
     */
    private function createApprovalThresholds(): void
    {
        $thresholds = [
            // --- Penarikan saldo ---
            ['withdrawal', 0, 500_000, null, 0],
            ['withdrawal', 500_000, 5_000_000, 'finance', 1],
            ['withdrawal', 5_000_000, null, 'super-admin', 2],

            // --- Penyesuaian saldo manual ---
            // Tidak ada tingkat otomatis. Setiap penyesuaian saldo, sekecil apa
            // pun, butuh penyetuju. Ini jalur yang paling mungkin dipakai untuk
            // fraud internal karena tidak meninggalkan jejak di luar ledger.
            ['balance_adjustment', 0, 1_000_000, 'finance', 1],
            ['balance_adjustment', 1_000_000, null, 'super-admin', 2],

            // --- Perubahan tarif ---
            ['pricing_change', 0, null, 'super-admin', 1],

            // --- Peluncuran promo ---
            ['promo_launch', 0, 10_000_000, 'ops-manager', 1],
            ['promo_launch', 10_000_000, null, 'super-admin', 1],

            // --- Refund massal ---
            ['bulk_refund', 0, 5_000_000, 'finance', 1],
            ['bulk_refund', 5_000_000, null, 'super-admin', 2],

            // --- Buka blokir driver ---
            ['driver_unban', 0, null, 'ops-manager', 1],

            // --- Komisi merchant ---
            ['merchant_commission', 0, null, 'ops-manager', 1],

            // --- Surge manual ---
            ['surge_manual', 0, null, 'ops-manager', 1],
        ];

        foreach ($thresholds as [$type, $min, $max, $role, $approvers]) {
            DB::table('approval_thresholds')->insert([
                'type' => $type,
                'min_amount' => $min,
                'max_amount' => $max,
                'required_role' => $role,
                'required_approvers' => $approvers,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Feature flag dan kill switch (blueprint admin bagian 11).
     *
     * Semuanya sudah ada dari awal, bukan ditambahkan saat dibutuhkan. Kill
     * switch yang baru dibuat saat sedang ada insiden adalah kill switch yang
     * tidak pernah diuji.
     */
    private function createFeatureFlags(): void
    {
        $flags = [
            ['orders.accepting_new', 'Terima order baru. Dimatikan berarti order berjalan tetap diselesaikan, tapi tidak ada order baru masuk', true],
            ['payment.wallet_enabled', 'Pembayaran dengan saldo wallet', true],
            ['payment.cash_enabled', 'Pembayaran tunai', true],
            ['payment.topup_enabled', 'Top up saldo', true],
            ['withdrawal.enabled', 'Penarikan saldo driver', true],
            ['withdrawal.auto_approve', 'Setujui otomatis penarikan di bawah ambang. Dimatikan berarti semua lewat review manual', true],
            ['matching.algorithm_v2', 'Algoritma matching versi baru, untuk uji di satu zona', false],
            ['surge.enabled', 'Surge pricing seluruh sistem', true],
            ['driver.registration_open', 'Pendaftaran driver baru', true],
            ['merchant.registration_open', 'Pendaftaran merchant baru', false],
            ['promo.enabled', 'Pemakaian kode promo', true],
            ['sos.enabled', 'Tombol darurat di aplikasi', true],
        ];

        foreach ($flags as [$key, $description, $enabled]) {
            DB::table('feature_flags')->insertOrIgnore([
                'key' => $key,
                'description' => $description,
                'is_enabled' => $enabled,
                'zone_ids' => null,
                'rollout_percent' => 100,
                'auto_revert_at' => null,
                'last_change_reason' => 'Nilai awal dari seeder',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -------------------------------------------------------------------------

    private function createSettings(): void
    {
        $settings = [
            // group, key, value, type, label, is_public
            ['general', 'app.name', 'Antaride', 'string', 'Nama aplikasi', true],
            ['general', 'app.support_phone', '+628000000000', 'string', 'Nomor bantuan', true],
            ['general', 'app.support_email', 'bantuan@antaride.id', 'string', 'Email bantuan', true],
            ['general', 'app.city', 'Medan', 'string', 'Kota operasional', true],

            // Versi minimum yang boleh dipakai. Ini yang memaksa pengguna
            // memperbarui app saat ada perubahan API yang tidak kompatibel.
            ['mobile', 'mobile.min_version_android', '1.0.0', 'string', 'Versi minimum Android', true],
            ['mobile', 'mobile.min_version_ios', '1.0.0', 'string', 'Versi minimum iOS', true],
            ['mobile', 'mobile.force_update', 'false', 'boolean', 'Paksa perbarui aplikasi', true],
            ['mobile', 'mobile.maintenance_message', '', 'text', 'Pesan pemeliharaan', true],

            ['order', 'order.search_timeout_seconds', '90', 'integer', 'Batas waktu mencari driver', false],
            ['order', 'order.free_cancel_window_seconds', '180', 'integer', 'Jendela batal gratis', false],
            ['order', 'order.cancellation_fee', '5000', 'money', 'Biaya pembatalan', false],
            ['order', 'order.driver_wait_minutes', '5', 'integer', 'Waktu tunggu driver di titik jemput', false],

            ['wallet', 'wallet.driver_cash_deposit_minimum', '20000', 'money', 'Saldo deposit minimum driver untuk order tunai', false],
            ['wallet', 'wallet.topup_min', '10000', 'money', 'Top up minimum', true],
            ['wallet', 'wallet.topup_max', '2000000', 'money', 'Top up maksimum', true],
            ['wallet', 'wallet.withdrawal_min', '50000', 'money', 'Penarikan minimum', false],
            ['wallet', 'wallet.withdrawal_fee', '2500', 'money', 'Biaya penarikan', false],

            ['driver', 'driver.min_rating_warning', '4.00', 'string', 'Rating batas peringatan', false],
            ['driver', 'driver.min_rating_suspend', '3.50', 'string', 'Rating batas penangguhan', false],
            ['driver', 'driver.max_cancellation_rate', '20.00', 'string', 'Batas persentase pembatalan', false],

            ['support', 'support.refund_limit_cs_agent', '50000', 'money', 'Batas refund CS agent', false],
            ['support', 'support.whatsapp', '+628000000000', 'string', 'WhatsApp bantuan', true],

            ['legal', 'legal.terms_url', '', 'string', 'URL syarat dan ketentuan', true],
            ['legal', 'legal.privacy_url', '', 'string', 'URL kebijakan privasi', true],
        ];

        foreach ($settings as [$group, $key, $value, $type, $label, $isPublic]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'group_name' => $group,
                'label' => $label,
                'is_public' => $isPublic,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
