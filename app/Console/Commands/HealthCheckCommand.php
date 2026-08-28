<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * Pemeriksaan kesehatan environment.
 *
 * Dibuat karena kelas kesalahan yang paling memakan waktu di proyek ini bukan
 * bug logika, tapi asumsi environment yang ternyata tidak benar: Redis yang
 * versinya tidak punya GEOSEARCH, PostGIS yang belum terpasang, prefix Redis
 * yang membuat Laravel dan service Go membaca key berbeda.
 *
 * Semuanya gejalanya sama: tidak ada error, hanya matching yang tidak pernah
 * menemukan driver. Perintah ini memeriksanya secara eksplisit supaya
 * ketidaksesuaian ditemukan dalam sepuluh detik, bukan setelah tiga jam.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'antaride:health {--json : Keluarkan hasil sebagai JSON}';

    protected $description = 'Periksa kesiapan database, Redis, dan service pendukung';

    /** @var array<int, array{name: string, status: string, detail: string}> */
    private array $results = [];

    private bool $hasFailure = false;

    public function handle(): int
    {
        $this->checkDatabase();
        $this->checkPostGis();
        $this->checkRedis();
        $this->checkRedisGeo();
        $this->checkSharedKeyPrefix();
        $this->checkMigrations();
        $this->checkConfigSanity();
        $this->checkOsrm();
        $this->checkCentrifugo();

        if ($this->option('json')) {
            $this->line(json_encode($this->results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->hasFailure ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Pemeriksaan', 'Status', 'Keterangan'], array_map(
            fn (array $r) => [$r['name'], $r['status'], $r['detail']],
            $this->results,
        ));

        if ($this->hasFailure) {
            $this->newLine();
            $this->error('  Ada pemeriksaan yang GAGAL. Perbaiki sebelum menjalankan service.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function checkDatabase(): void
    {
        try {
            $version = DB::selectOne('SELECT version() AS v')->v;
            $db = DB::selectOne('SELECT current_database() AS d')->d;

            preg_match('/PostgreSQL (\d+\.\d+)/', $version, $m);
            $short = $m[1] ?? '?';

            $this->recordPass('PostgreSQL', "versi {$short}, database \"{$db}\"");
        } catch (\Throwable $e) {
            $this->recordFail('PostgreSQL', 'tidak tersambung: '.$this->trim($e->getMessage()));
        }
    }

    private function checkPostGis(): void
    {
        try {
            $installed = DB::selectOne("SELECT extversion AS v FROM pg_extension WHERE extname='postgis'");

            if ($installed !== null) {
                $this->recordPass('PostGIS', "terpasang, versi {$installed->v}");

                return;
            }

            $available = DB::selectOne("SELECT 1 AS ok FROM pg_available_extensions WHERE name='postgis'");
            $driver = config('antaride.geo.zone_driver');

            if ($available !== null) {
                $this->recordWarn('PostGIS', 'tersedia tapi belum diaktifkan. Jalankan: php artisan geo:enable-postgis');

                return;
            }

            if ($driver === 'postgis') {
                $this->recordFail(
                    'PostGIS',
                    'belum terpasang, tapi GEO_ZONE_DRIVER=postgis. Pasang PostGIS 3.6.2 atau set GEO_ZONE_DRIVER=native',
                );

                return;
            }

            $this->recordWarn('PostGIS', 'belum terpasang, memakai resolver zona "native"');
        } catch (\Throwable $e) {
            $this->recordFail('PostGIS', $this->trim($e->getMessage()));
        }
    }

    private function checkRedis(): void
    {
        try {
            $info = Redis::connection('shared')->info();
            $version = $info['redis_version'] ?? ($info['Server']['redis_version'] ?? null);

            if ($version === null) {
                $this->recordWarn('Redis', 'tersambung, tapi versi tidak terbaca');

                return;
            }

            $this->recordPass('Redis', "versi {$version}, client ".config('database.redis.client'));
        } catch (\Throwable $e) {
            $this->recordFail('Redis', 'tidak tersambung: '.$this->trim($e->getMessage()));
        }
    }

    /**
     * GEOSEARCH baru ada di Redis 6.2. Build Windows yang umum masih 5.0, dan
     * di sana perintahnya harus turun ke GEORADIUS.
     *
     * Kalau konfigurasinya salah, matching akan melempar exception pada setiap
     * order. Lebih baik ketahuan di sini.
     */
    private function checkRedisGeo(): void
    {
        $configured = config('antaride.geo.redis_command');

        try {
            $info = Redis::connection('shared')->info();
            $version = $info['redis_version'] ?? ($info['Server']['redis_version'] ?? '0.0.0');

            $supportsGeoSearch = version_compare($version, '6.2.0', '>=');
            $expected = $supportsGeoSearch ? 'geosearch' : 'georadius';

            if ($configured !== $expected) {
                $this->recordFail(
                    'Perintah GEO Redis',
                    "REDIS_GEO_COMMAND={$configured}, tapi Redis {$version} butuh \"{$expected}\"",
                );

                return;
            }

            $note = $supportsGeoSearch
                ? 'GEOSEARCH didukung'
                : "Redis {$version} tidak punya GEOSEARCH, memakai GEORADIUS";

            $this->recordPass('Perintah GEO Redis', $note);
        } catch (\Throwable $e) {
            $this->recordFail('Perintah GEO Redis', $this->trim($e->getMessage()));
        }
    }

    /**
     * Koneksi 'shared' HARUS tanpa prefix, karena location service Go menulis
     * key mentah ke Redis yang sama.
     *
     * Kalau prefix ikut terpasang, Laravel dan Go akan membaca dan menulis key
     * yang berbeda tanpa satu pun error muncul. Gejalanya: driver online di
     * app, tapi matching selalu melaporkan tidak ada driver.
     */
    private function checkSharedKeyPrefix(): void
    {
        $probe = 'antaride:health:prefix-probe';

        try {
            Redis::connection('shared')->setex($probe, 10, '1');

            // Dibaca lewat koneksi default yang berprefix. Kalau key-nya
            // ketemu di sana, berarti koneksi shared ikut berprefix, dan
            // itu justru yang tidak kita inginkan.
            $raw = Redis::connection('shared')->exists($probe);

            $configuredPrefix = config('database.redis.shared.options.prefix');

            Redis::connection('shared')->del($probe);

            if ($configuredPrefix !== '') {
                $this->recordFail(
                    'Prefix Redis bersama',
                    'koneksi "shared" punya prefix. Service Go tidak akan melihat key yang sama',
                );

                return;
            }

            if ($raw !== 1) {
                $this->recordFail('Prefix Redis bersama', 'key uji tidak terbaca kembali');

                return;
            }

            $this->recordPass('Prefix Redis bersama', 'kosong, key dipakai bersama service Go');
        } catch (\Throwable $e) {
            $this->recordFail('Prefix Redis bersama', $this->trim($e->getMessage()));
        }
    }

    private function checkMigrations(): void
    {
        try {
            $ran = DB::table('migrations')->count();
            $files = glob(database_path('migrations/*.php')) ?: [];
            $pending = count($files) - $ran;

            if ($pending > 0) {
                $this->recordWarn('Migration', "{$pending} belum dijalankan. Jalankan: php artisan migrate");

                return;
            }

            $this->recordPass('Migration', "{$ran} sudah dijalankan, tidak ada yang tertunda");
        } catch (\Throwable $e) {
            $this->recordFail('Migration', 'tabel migrations tidak terbaca. Jalankan: php artisan migrate');
        }
    }

    /**
     * Bobot skoring matching harus berjumlah 1.00, supaya skor kandidat berada
     * di rentang yang bisa dibandingkan antar zona. Jumlah yang bukan 1 tidak
     * menyebabkan error, hanya membuat urutan kandidat pelan-pelan tidak masuk
     * akal, dan itu jenis kesalahan yang tidak akan pernah ada yang menyadari.
     */
    private function checkConfigSanity(): void
    {
        $weights = config('antaride.matching.weights', []);
        $sum = round(array_sum($weights), 4);

        if (abs($sum - 1.0) > 0.0001) {
            $this->recordFail('Bobot matching', "berjumlah {$sum}, seharusnya 1.00");

            return;
        }

        $this->recordPass('Bobot matching', 'berjumlah 1.00');
    }

    private function checkOsrm(): void
    {
        $url = config('services.osrm.url');

        try {
            // Rute pendek di Medan sebagai uji nyata, bukan sekadar ping.
            $response = Http::timeout(3)->get(
                "{$url}/route/v1/driving/98.6722,3.5952;98.6800,3.6000",
                ['overview' => 'false'],
            );

            if ($response->successful() && ($response->json('code') === 'Ok')) {
                $this->recordPass('OSRM', "menjawab di {$url}");

                return;
            }

            $this->recordWarn('OSRM', "menjawab tapi tidak dengan Ok di {$url}");
        } catch (\Throwable) {
            $this->recordWarn('OSRM', "tidak menjawab di {$url}. Estimasi jarak akan gagal");
        }
    }

    private function checkCentrifugo(): void
    {
        $url = config('services.centrifugo.url');

        try {
            $response = Http::timeout(2)->get("{$url}/health");

            if ($response->successful()) {
                $this->recordPass('Centrifugo', "menjawab di {$url}");

                return;
            }

            $this->recordWarn('Centrifugo', "menjawab {$response->status()} di {$url}");
        } catch (\Throwable) {
            $this->recordWarn('Centrifugo', "tidak menjawab di {$url}. Update realtime tidak terkirim");
        }
    }

    // -------------------------------------------------------------------------

    private function recordPass(string $name, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => 'OK', 'detail' => $detail];
    }

    private function recordWarn(string $name, string $detail): void
    {
        $this->results[] = ['name' => $name, 'status' => 'PERHATIAN', 'detail' => $detail];
    }

    private function recordFail(string $name, string $detail): void
    {
        $this->hasFailure = true;
        $this->results[] = ['name' => $name, 'status' => 'GAGAL', 'detail' => $detail];
    }

    private function trim(string $message): string
    {
        $first = explode("\n", $message)[0];

        return mb_strimwidth($first, 0, 90, '...');
    }
}
