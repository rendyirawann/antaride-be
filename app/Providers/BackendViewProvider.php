<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Angka-angka yang muncul di layout panel admin.
 *
 * ============================================================================
 *  KENAPA VIEW COMPOSER, BUKAN DIKIRIM SETIAP CONTROLLER
 * ============================================================================
 *  Sidebar dan menu menampilkan jumlah antrean verifikasi, penarikan menunggu,
 *  dan order macet. Angka-angka itu muncul di SETIAP halaman panel.
 *
 *  Kalau setiap controller harus mengirimnya, akan ada halaman yang lupa — dan
 *  yang tampil bukan "tidak ada angka" tapi NOL, karena Blade memakai `?? 0`.
 *  Nol yang salah di panel ops lebih berbahaya daripada tidak ada angka sama
 *  sekali: dia terlihat seperti jawaban, dan antrean yang sebenarnya menumpuk
 *  tampak kosong.
 *
 *  View composer menutup kemungkinan itu: tidak ada controller yang bisa lupa.
 * ============================================================================
 *
 * ============================================================================
 *  SEMUA ANGKA DI-CACHE, DAN ITU BUKAN OPTIMASI PREMATUR
 * ============================================================================
 *  Tanpa cache, setiap pemuatan halaman panel — termasuk setiap permintaan
 *  DataTables saat staf mengetik di kolom pencarian — menjalankan empat query
 *  agregat. Pada tabel order yang sudah besar, itu berarti panel admin menjadi
 *  beban terbesar di database, dan yang melambat bukan panelnya saja tapi
 *  penerimaan order.
 *
 *  15 detik dipilih supaya angkanya tetap terasa hidup untuk pekerjaan
 *  operasional, tapi tidak dihitung ulang untuk setiap penekanan tombol.
 * ============================================================================
 */
class BackendViewProvider extends ServiceProvider
{
    private const CACHE_SECONDS = 15;

    public function boot(): void
    {
        View::composer('backend.layout.*', function ($view): void {
            /*
             * Dilewati kalau tidak ada admin yang login.
             *
             * Halaman login memakai layout auth yang berbeda, tapi halaman error
             * bisa merender layout ini tanpa sesi. Menjalankan query pada
             * keadaan itu berarti halaman error 500 menghasilkan error kedua.
             */
            if (auth('admin')->guest()) {
                return;
            }

            $view->with($this->ringkasan());
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function ringkasan(): array
    {
        return Cache::remember(
            'backend:ringkasan',
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): array => [
                'ringkasan' => [
                    'order_berjalan' => $this->orderBerjalan(),
                    'order_macet' => $this->orderMacet(),
                    'driver_online' => $this->driverOnline(),
                ],
                'jumlahVerifikasiTertunda' => $this->verifikasiTertunda(),
                'jumlahPenarikanTertunda' => $this->penarikanTertunda(),
            ],
        );
    }

    private function orderBerjalan(): int
    {
        return DB::table('orders')
            ->whereIn('status', OrderStatus::activeValues())
            ->count();
    }

    /**
     * Order yang sudah terlalu lama mencari driver.
     *
     * Ini satu-satunya angka di panel yang berarti ada penumpang sedang menatap
     * layar tanpa jawaban. Ambangnya dari config supaya tim ops bisa
     * menyesuaikannya per kota tanpa deploy.
     */
    private function orderMacet(): int
    {
        $ambang = (int) config('antaride.live_map.stuck_order_highlight_seconds', 60);

        return DB::table('orders')
            ->where('status', OrderStatus::Searching->value)
            ->where('requested_at', '<', now()->subSeconds($ambang))
            ->count();
    }

    /**
     * Driver yang sedang siap menerima order.
     *
     * Dibaca dari Redis, bukan dari `driver_sessions`. Keduanya berbeda arti:
     * sesi yang terbuka berarti dia MENYATAKAN diri bekerja, sementara set
     * ketersediaan di Redis berarti dia BENAR-BENAR bisa ditawari sekarang.
     *
     * Yang berguna untuk tim ops adalah yang kedua — driver yang sesinya terbuka
     * tapi HP-nya mati tidak akan mengambil satu pun order, dan menghitungnya
     * sebagai "online" membuat rasio permintaan-pasokan terlihat lebih sehat
     * daripada kenyataannya.
     */
    private function driverOnline(): int
    {
        try {
            $index = app(DriverLocationIndex::class);
            $zoneIds = DB::table('zones')->where('is_active', true)->pluck('id');

            $total = 0;

            foreach (DB::table('service_types')->where('is_active', true)->pluck('code') as $code) {
                foreach ($zoneIds as $zoneId) {
                    $total += $index->availableCount((string) $code, (int) $zoneId);
                }
            }

            return $total;
        } catch (\Throwable) {
            /*
             * Redis mati TIDAK boleh menjatuhkan panel admin.
             *
             * Justru saat Redis bermasalah, panel adalah alat pertama yang
             * dibuka untuk mencari tahu apa yang terjadi. Halaman yang gagal
             * dimuat karena angka di sidebar-nya tidak bisa dihitung adalah
             * kegagalan yang paling tidak tepat waktunya.
             *
             * -1 dipakai sebagai penanda "tidak diketahui", dan Blade
             * menampilkannya apa adanya. Menampilkan 0 akan berarti "tidak ada
             * driver online", yang merupakan kesimpulan yang sama sekali
             * berbeda.
             */
            return -1;
        }
    }

    private function verifikasiTertunda(): int
    {
        return DB::table('driver_documents')
            ->where('status', 'pending')
            ->count();
    }

    private function penarikanTertunda(): int
    {
        return DB::table('withdrawals')
            ->whereIn('status', ['requested', 'reviewing'])
            ->count();
    }
}
