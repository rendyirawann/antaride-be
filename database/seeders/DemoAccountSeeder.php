<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Driver\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Akun demo untuk ketiga aplikasi.
 *
 * ============================================================================
 *  AKUN YANG SIAP DIPAKAI, BUKAN SEKADAR BISA MASUK
 * ============================================================================
 *  Akun demo yang hanya bisa masuk lalu mentok di layar pertama tidak menguji
 *  apa pun. Yang paling sering terlewat justru pada driver — dan setiap satu
 *  yang kurang menghasilkan kebuntuan yang berbeda:
 *
 *    tanpa verified_at        `GoOnline` menolak: "belum terverifikasi"
 *    tanpa kendaraan          tidak ada kendaraan aktif untuk dipakai
 *    tanpa eligibility        online berhasil, tapi tidak ada layanan aktif —
 *                             jadi tidak pernah masuk indeks ketersediaan
 *    tanpa dokumen disetujui  `/driver/documents` menyatakan belum lengkap
 *    tanpa SALDO              inilah yang paling membingungkan: driver online,
 *                             posisinya tercatat, tapi TIDAK PERNAH ditawari
 *                             order tunai. `onlyWithCashDeposit` menyaringnya,
 *                             dan tidak ada satu pun galat yang muncul.
 *
 *  Yang terakhir itu ditemukan lewat UAT: seluruh alur terlihat benar sampai
 *  tawaran yang tidak pernah datang. Karena itu driver demo di sini diberi saldo
 *  di atas `driver_cash_deposit_minimum`.
 * ============================================================================
 *
 * ============================================================================
 *  IDEMPOTEN — AMAN DIJALANKAN BERULANG
 * ============================================================================
 *  Dijalankan setiap deploy, jadi tidak boleh menghasilkan akun ganda atau
 *  menggandakan saldo. Yang dicari `updateOrCreate` berdasarkan nomor HP, dan
 *  saldo hanya ditambah kalau memang masih kurang.
 * ============================================================================
 */
class DemoAccountSeeder extends Seeder
{
    /**
     * Nomor HP akun demo.
     *
     * Memakai awalan `0899` yang tidak dipakai operator mana pun di Indonesia,
     * jadi tidak mungkin bertabrakan dengan nomor pengguna sungguhan — dan
     * kalau ada yang mencoba OTP ke nomor ini, tidak ada orang lain yang
     * menerimanya.
     */
    private const HP = [
        'customer' => '0899000000001',
        'driver' => '0899000000002',
        'merchant' => '0899000000003',
    ];

    public function run(): void
    {
        $penumpang = $this->buatUser(
            role: 'customer',
            nama: 'Budi Penumpang (Demo)',
            urutan: 1,
            catatan: 'Akun penumpang siap pesan. Saldo dompet Rp 100.000.',
        );

        $this->isiDompet('user', (int) $penumpang->id, 100_000);

        $driver = $this->buatUser(
            role: 'driver',
            nama: 'Sutrisno Driver (Demo)',
            urutan: 1,
            catatan: 'Terverifikasi, dokumen lengkap, saldo Rp 100.000, layanan ojek aktif.',
        );

        $this->siapkanDriver($driver);

        $this->buatUser(
            role: 'merchant',
            nama: 'Warung Sederhana (Demo)',
            urutan: 1,
            catatan: 'Akun pemilik merchant. API merchant belum ada di Fase 1.',
        );

        $this->command?->info('  Akun demo siap:');

        foreach (self::HP as $role => $hp) {
            $this->command?->line(sprintf('    %-9s %s', $role, $hp));
        }
    }

    // =========================================================================

    private function buatUser(
        string $role,
        string $nama,
        int $urutan,
        string $catatan,
    ): User {
        return User::updateOrCreate(
            ['phone' => self::HP[$role]],
            [
                'name' => $nama,
                'status' => 'active',

                // Nomor dianggap terverifikasi: akun demo tidak melewati OTP,
                // dan `phone_verified_at` yang kosong membuat sebagian layar
                // menampilkan ajakan verifikasi yang tidak bisa diselesaikan.
                'phone_verified_at' => now(),

                'demo_role' => $role,
                'demo_order' => $urutan,
                'demo_note' => $catatan,
            ],
        );
    }

    /**
     * Isi dompet sampai minimal sekian, bukan menambah sekian.
     *
     * Seeder ini dijalankan ulang setiap deploy. `credit` tanpa syarat akan
     * menggandakan saldo setiap kali — dan saldo demo yang tumbuh sendiri
     * membuat pengujian batas saldo tidak berarti.
     */
    private function isiDompet(string $ownerType, int $ownerId, int $minimal): void
    {
        $dompet = Wallet::forOwner($ownerType, $ownerId);

        if ((int) $dompet->balance >= $minimal) {
            return;
        }

        $kurang = $minimal - (int) $dompet->balance;

        $platform = Wallet::forOwner('platform', 1);
        $jumlah = Money::of($kurang);

        app(PostLedgerEntries::class)->handle([
            LedgerEntry::credit($dompet->id, 'topup', $jumlah, description: 'Saldo akun demo'),
            LedgerEntry::debit($platform->id, 'topup', $jumlah, description: 'Saldo akun demo'),
        ]);
    }

    /**
     * Driver yang benar-benar bisa menerima order.
     *
     * Setiap langkah di sini menutup satu kebuntuan yang berbeda — lihat
     * docblock kelas.
     */
    private function siapkanDriver(User $user): void
    {
        $driver = Driver::updateOrCreate(
            ['user_id' => $user->id],
            [
                'full_name' => 'Sutrisno Driver (Demo)',
                'status' => 'active',
                'verified_at' => now(),
                'city' => 'Medan',
                'address' => 'Jl. Demo No. 1, Medan',
                'nik' => '1271000000000001',
            ],
        );

        // Kendaraan aktif.
        if ($driver->vehicles()->where('is_active', true)->doesntExist()) {
            $driver->vehicles()->create([
                'uuid' => (string) Str::uuid7(),
                'type' => 'motorcycle',
                'brand' => 'Honda',
                'model' => 'Beat',
                'year' => 2021,
                'plate_number' => 'BK 1234 DEM',
                'color' => 'Hitam',
                'is_active' => true,
            ]);
        }

        // Layanan ojek, dinyalakan admin DAN driver.
        //
        // Keduanya wajib: `is_enabled` keputusan admin, `enabled_by_driver`
        // sakelar driver sendiri. Salah satu mati berarti dia tidak pernah masuk
        // indeks ketersediaan.
        $rideBike = (int) DB::table('service_types')->where('code', 'ride_bike')->value('id');

        if ($rideBike > 0) {
            DB::table('driver_service_eligibility')->updateOrInsert(
                ['driver_id' => $driver->id, 'service_type_id' => $rideBike],
                [
                    'is_enabled' => true,
                    'enabled_by_driver' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // Dokumen wajib, semuanya disetujui.
        //
        // `file_path` menunjuk berkas yang TIDAK ada, dan itu disengaja: akun
        // demo tidak boleh menyeret foto KTP sungguhan ke dalam repo maupun ke
        // disk server. Pratinjaunya akan gagal dimuat di panel verifikasi, dan
        // itu benar — tidak ada dokumen untuk dilihat.
        /** @var list<string> $wajib */
        $wajib = (array) config('antaride.kyc.required_documents', []);

        /** @var list<string> $berlakuTerbatas */
        $berlakuTerbatas = (array) config('antaride.kyc.expiring_documents', []);

        foreach ($wajib as $jenis) {
            DB::table('driver_documents')->updateOrInsert(
                ['driver_id' => $driver->id, 'type' => $jenis],
                [
                    'uuid' => (string) Str::uuid7(),
                    'file_path' => 'demo/tidak-ada.jpg',
                    'status' => 'approved',
                    'reviewed_at' => now(),

                    // Tanggal berlaku HANYA untuk jenis yang memang punya, dan
                    // jauh di depan. Dokumen demo yang kadaluarsa akan menolak
                    // driver online — dengan pesan yang benar, tapi bukan itu
                    // yang sedang diuji.
                    'expires_at' => in_array($jenis, $berlakuTerbatas, true)
                        ? now()->addYears(3)->toDateString()
                        : null,

                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // Saldo di atas batas deposit order tunai.
        //
        // Tanpa ini driver online, posisinya tercatat, dan TIDAK PERNAH ditawari
        // order tunai — tanpa satu pun galat. Lihat docblock kelas.
        $minimum = (int) config('antaride.wallet.driver_cash_deposit_minimum', 20_000);

        $this->isiDompet('driver', (int) $driver->id, max($minimum * 5, 100_000));
    }
}
