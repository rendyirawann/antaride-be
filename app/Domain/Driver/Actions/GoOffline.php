<?php

declare(strict_types=1);

namespace App\Domain\Driver\Actions;

use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverSession;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\DriverBusyException;
use Illuminate\Support\Facades\DB;

/**
 * Driver berhenti menerima order.
 *
 * ============================================================================
 *  URUTANNYA TERBALIK DARI GoOnline, DAN ITU DISENGAJA
 * ============================================================================
 *  Saat online: Postgres dulu, Redis kemudian.
 *  Saat offline: Redis dulu, Postgres kemudian.
 *
 *  Prinsipnya sama untuk keduanya: yang dilakukan lebih dulu adalah yang
 *  kegagalannya paling tidak merugikan.
 *
 *  Kalau saat offline Postgres ditulis lebih dulu lalu prosesnya mati, driver
 *  akan tercatat tidak bekerja tapi MASIH terdaftar siap di Redis — dia
 *  menerima penawaran order sambil aplikasinya sudah tertutup. Penumpang
 *  menunggu 15 detik untuk penawaran yang tidak akan pernah dibuka.
 *
 *  Sebaliknya, kalau Redis dicabut lebih dulu lalu prosesnya mati, yang terjadi
 *  hanya sesi kerja yang tertinggal terbuka. Itu masalah pelaporan, bukan
 *  masalah operasional, dan job pembersih menutupnya.
 * ============================================================================
 */
class GoOffline
{
    public function __construct(
        private readonly DriverLocationIndex $locationIndex,
    ) {}

    public function handle(Driver $driver, bool $force = false): ?DriverSession
    {
        $driverId = (int) $driver->getKey();

        if (! $force) {
            $this->assertNoActiveOrder($driverId);
        }

        // Redis dulu. Lihat penjelasan di docblock kelas.
        $this->locationIndex->markUnavailableEverywhere($driverId);
        $this->locationIndex->forget($driverId);

        return $this->closeSession($driver);
    }

    // -------------------------------------------------------------------------

    /**
     * Driver dengan order berjalan tidak boleh offline.
     *
     * Kalau boleh, penumpang yang sedang diantar akan kehilangan titik driver di
     * peta tanpa penjelasan apa pun, dan tidak ada yang tahu order itu masih
     * berjalan atau sudah ditinggalkan.
     *
     * `$force` ada untuk intervensi admin: HP driver mati total, ordernya
     * menggantung, dan ops perlu membereskannya. Jalur itu selalu disertai
     * pembatalan ordernya, bukan dipakai sendiri.
     */
    private function assertNoActiveOrder(int $driverId): void
    {
        $hasActive = DB::table('orders')
            ->where('driver_id', $driverId)
            ->whereIn('status', OrderStatus::activeValues())
            ->exists();

        if ($hasActive) {
            throw DriverBusyException::alreadyHasActiveOrder();
        }
    }

    /**
     * Tutup sesi kerja dan hitung jam kerjanya.
     */
    private function closeSession(Driver $driver): ?DriverSession
    {
        /** @var DriverSession|null $session */
        $session = $driver->sessions()->whereNull('ended_at')->first();

        if ($session === null) {
            // Sudah offline. Idempoten: menekan tombol dua kali tidak error.
            return null;
        }

        $endedAt = now();

        /*
         * Jam kerja dihitung dari cap waktu, bukan diakumulasi per ping.
         *
         * Akumulasi per ping akan kehilangan waktu setiap kali aplikasi driver
         * kehilangan sinyal beberapa menit — dan justru saat itulah driver
         * sedang bekerja di daerah yang paling sulit. Menghitung dari selisih
         * dua cap waktu membuat sinyal yang hilang tidak mengurangi jam
         * kerjanya.
         */
        $session->ended_at = $endedAt;
        $session->online_seconds = max(
            0,
            (int) floor($session->started_at->diffInSeconds($endedAt, absolute: true)),
        );

        $session->save();

        return $session;
    }
}
