<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Waktu dalam zona bisnis, terpisah dari waktu penyimpanan.
 *
 * ============================================================================
 *  KENAPA CLASS INI ADA
 * ============================================================================
 *  Aplikasi ini berjalan di UTC, dan itu keputusan yang benar: timestamp yang
 *  disimpan tidak boleh bergantung pada setelan server, dan seluruh kolom
 *  timestamp di database memakai UTC.
 *
 *  Tapi keputusan BISNIS tidak dibuat dalam UTC. "Jam pulang kerja" berarti
 *  17:00 WIB, bukan 17:00 UTC. "Warung buka sampai jam 2 pagi" berarti 02:00
 *  WIB. "Pelanggaran GPS palsu hari ini" berarti sejak tengah malam WIB.
 *
 *  Sebelum class ini ada, perbandingan jam dilakukan langsung pada waktu UTC.
 *  Akibatnya nyata dan sudah dibuktikan: aturan surge dengan jadwal
 *  17:00-19:30 TIDAK PERNAH menyala pada jam pulang kerja sungguhan, karena
 *  17:30 WIB adalah 10:30 UTC dan perbandingannya gagal. Yang akan menyala
 *  justru tengah malam.
 *
 *  Tidak ada satu pun error yang muncul untuk kesalahan seperti itu. Yang
 *  terlihat hanya surge yang tidak pernah aktif, dan tidak ada yang tahu
 *  kenapa.
 * ============================================================================
 *
 *  ATURANNYA:
 *
 *    Disimpan ke database        -> UTC, pakai now() biasa
 *    Dibandingkan sebagai jam    -> BusinessClock::at()
 *    Batas hari untuk agregasi   -> BusinessClock::startOfToday()
 *    Ditampilkan ke pengguna     -> BusinessClock::at()
 */
final class BusinessClock
{
    /**
     * Zona waktu bisnis.
     *
     * Dibaca dari config, bukan dari APP_TIMEZONE. APP_TIMEZONE sengaja
     * dibiarkan UTC supaya penyimpanan tidak terpengaruh.
     */
    public static function timezone(): string
    {
        return (string) config('antaride.timezone', 'Asia/Jakarta');
    }

    /**
     * Saat ini, dalam zona bisnis.
     */
    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    /**
     * Ubah waktu apa pun ke zona bisnis.
     *
     * Ini yang HARUS dipakai sebelum membandingkan jam, hari, atau tanggal.
     * Waktu yang masuk boleh UTC, boleh sudah berzona lain; hasilnya selalu
     * zona bisnis.
     */
    public static function at(?DateTimeInterface $moment = null): Carbon
    {
        if ($moment === null) {
            return self::now();
        }

        return Carbon::instance(
            \DateTimeImmutable::createFromInterface($moment)
        )->setTimezone(self::timezone());
    }

    /**
     * Jam dalam sehari sebagai string HH:MM:SS, dalam zona bisnis.
     *
     * Bentuknya cocok untuk dibandingkan langsung dengan kolom TIME di
     * database, yang menyimpan jam bisnis apa adanya tanpa zona.
     */
    public static function timeOfDay(?DateTimeInterface $moment = null): string
    {
        return self::at($moment)->format('H:i:s');
    }

    /**
     * Hari dalam seminggu (0 = Minggu), dalam zona bisnis.
     */
    public static function dayOfWeek(?DateTimeInterface $moment = null): int
    {
        return self::at($moment)->dayOfWeek;
    }

    /**
     * Tanggal sebagai string Y-m-d, dalam zona bisnis.
     */
    public static function date(?DateTimeInterface $moment = null): string
    {
        return self::at($moment)->format('Y-m-d');
    }

    /**
     * Awal hari bisnis, dikembalikan dalam UTC.
     *
     * Untuk dipakai membandingkan dengan kolom timestamp di database, yang
     * isinya UTC. Tengah malam WIB adalah 17:00 UTC hari sebelumnya, dan
     * itulah nilai yang benar untuk klausa WHERE.
     *
     * Memakai now()->startOfDay() langsung akan memberi tengah malam UTC, yang
     * berarti "hari ini" dimulai jam 7 pagi WIB. Perhitungan pelanggaran harian
     * dan agregasi metrik akan salah tujuh jam.
     */
    public static function startOfToday(): Carbon
    {
        return self::now()->startOfDay()->utc();
    }

    /**
     * Awal hari bisnis untuk tanggal tertentu, dikembalikan dalam UTC.
     */
    public static function startOfDay(?DateTimeInterface $moment = null): Carbon
    {
        return self::at($moment)->startOfDay()->utc();
    }

    /**
     * Akhir hari bisnis, dikembalikan dalam UTC.
     */
    public static function endOfDay(?DateTimeInterface $moment = null): Carbon
    {
        return self::at($moment)->endOfDay()->utc();
    }

    /**
     * Rentang satu hari bisnis dalam UTC, untuk klausa whereBetween.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function dayRange(?DateTimeInterface $moment = null): array
    {
        return [self::startOfDay($moment), self::endOfDay($moment)];
    }

    /**
     * Apakah sebuah jam berada dalam rentang jam bisnis.
     *
     * Menangani rentang yang melewati tengah malam: 22:00 sampai 02:00
     * mencakup 23:00 dan 01:00. Tanpa penanganan itu, jadwal malam tidak akan
     * pernah aktif.
     *
     * @param  string  $start  HH:MM:SS
     * @param  string  $end  HH:MM:SS
     */
    public static function timeWithinRange(
        string $start,
        string $end,
        ?DateTimeInterface $moment = null,
    ): bool {
        $now = self::timeOfDay($moment);
        $start = substr($start, 0, 8);
        $end = substr($end, 0, 8);

        return $start > $end
            ? ($now >= $start || $now < $end)
            : ($now >= $start && $now < $end);
    }
}
