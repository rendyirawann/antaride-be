<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengaktifkan PostGIS pada database yang sudah berjalan.
 *
 * ============================================================================
 *  KENAPA PERINTAH INI HARUS ADA
 * ============================================================================
 *  Tiga tempat menyebutkan perintah ini sebagai jalan keluar: migration
 *  ekstensi, docblock migration katalog, dan pesan `antaride:health`. Sampai
 *  sekarang perintahnya tidak pernah dibuat.
 *
 *  Akibatnya bukan sekadar tidak nyaman. Urutan yang paling wajar terjadi
 *  adalah: migrate jalan dulu (PostGIS belum ada), lalu PostGIS dipasang lewat
 *  StackBuilder. Pada titik itu tabel `zones` sudah ada TANPA kolom geometry,
 *  dan satu-satunya saran yang diberikan sistem adalah menjalankan perintah
 *  yang tidak ada. Yang tersisa hanya `migrate:fresh` — yaitu menghapus seluruh
 *  data.
 *
 *  Perintah ini melakukan langkah yang sama seperti migration, dengan urutan
 *  yang benar, dan idempoten: dijalankan dua kali tidak merusak apa pun.
 * ============================================================================
 */
class EnablePostGisCommand extends Command
{
    protected $signature = 'geo:enable-postgis
                            {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Aktifkan PostGIS dan isi kolom geometry zona yang sudah ada';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->components->info('Mengaktifkan PostGIS untuk Antaride.');

        if (! $this->createExtension()) {
            return self::FAILURE;
        }

        $this->addGeometryColumn();
        $this->createSyncTrigger();
        $backfilled = $this->backfillGeometry();
        $this->enforceGeometryPresent();

        $this->newLine();
        $this->components->info('PostGIS aktif.');
        $this->components->twoColumnDetail('Zona terisi geometry', (string) $backfilled);
        $this->components->twoColumnDetail(
            'Langkah berikutnya',
            'set GEO_ZONE_DRIVER=postgis di .env, lalu php artisan antaride:health'
        );

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function confirmToProceed(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->components->warn(
            'Perintah ini mengubah skema tabel zones (menambah kolom, trigger, dan constraint).'
        );

        return $this->confirm('Lanjutkan?', default: true);
    }

    private function createExtension(): bool
    {
        if ($this->extensionInstalled()) {
            $this->components->twoColumnDetail('Ekstensi postgis', 'sudah aktif');

            return true;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        } catch (\Throwable $e) {
            $this->components->error('Gagal mengaktifkan PostGIS.');
            $this->line('  '.$e->getMessage());
            $this->newLine();
            $this->line('  PostGIS harus DIPASANG lebih dulu di server PostgreSQL:');
            $this->line('  Application Stack Builder -> Spatial Extensions -> PostGIS 3.6.2');
            $this->line('  Versi 3.6.2 adalah yang pertama mendukung PostgreSQL 18.');

            return false;
        }

        $this->components->twoColumnDetail('Ekstensi postgis', '<fg=green>diaktifkan</>');

        return true;
    }

    private function extensionInstalled(): bool
    {
        return DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'postgis'") !== null;
    }

    private function addGeometryColumn(): void
    {
        if (Schema::hasColumn('zones', 'polygon')) {
            $this->components->twoColumnDetail('Kolom zones.polygon', 'sudah ada');

            return;
        }

        DB::statement('ALTER TABLE zones ADD COLUMN polygon geometry(Polygon, 4326)');

        // GiST index inilah yang membuat ST_Covers cepat. Tanpa dia, setiap
        // quote menguji titik terhadap setiap polygon zona satu per satu.
        DB::statement('CREATE INDEX IF NOT EXISTS zones_polygon_gist ON zones USING gist (polygon)');

        $this->components->twoColumnDetail('Kolom zones.polygon', '<fg=green>ditambahkan</>');
    }

    private function createSyncTrigger(): void
    {
        // Trigger, bukan kode aplikasi. Siapa pun yang menulis zones setelah
        // ini, termasuk lewat psql langsung, mendapat kolom geometry yang
        // konsisten dengan GeoJSON-nya.
        DB::unprepared('
            CREATE OR REPLACE FUNCTION zones_sync_polygon() RETURNS trigger AS $$
            BEGIN
                NEW.polygon := ST_SetSRID(
                    ST_GeomFromGeoJSON(NEW.polygon_geojson::text),
                    4326
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ');

        DB::unprepared('DROP TRIGGER IF EXISTS zones_sync_polygon_trigger ON zones');
        DB::unprepared('
            CREATE TRIGGER zones_sync_polygon_trigger
            BEFORE INSERT OR UPDATE OF polygon_geojson ON zones
            FOR EACH ROW EXECUTE FUNCTION zones_sync_polygon();
        ');

        $this->components->twoColumnDetail('Trigger zones_sync_polygon', '<fg=green>terpasang</>');
    }

    /**
     * @return int jumlah zona yang baru terisi
     */
    private function backfillGeometry(): int
    {
        /*
         * Ini langkah yang paling mudah terlupakan, dan yang paling mahal kalau
         * terlupa.
         *
         * Trigger di atas hanya berjalan saat INSERT atau UPDATE. Zona yang
         * sudah tersimpan sebelum PostGIS aktif akan punya polygon NULL, dan
         * kegagalannya SENYAP: ST_Covers(NULL, titik) menghasilkan NULL, WHERE
         * membuangnya, dan resolver menyimpulkan "di luar area layanan" untuk
         * SELURUH kota. Order berhenti bisa dibuat, dan tidak ada satu pun
         * error di log yang menjelaskannya.
         */
        $affected = DB::update('
            UPDATE zones
            SET polygon = ST_SetSRID(ST_GeomFromGeoJSON(polygon_geojson::text), 4326)
            WHERE polygon IS NULL
        ');

        $this->components->twoColumnDetail(
            'Backfill geometry',
            $affected > 0 ? "<fg=green>{$affected} zona</>" : 'tidak ada yang perlu diisi'
        );

        return $affected;
    }

    private function enforceGeometryPresent(): void
    {
        $exists = DB::selectOne("
            SELECT 1 AS ok FROM pg_constraint WHERE conname = 'zones_polygon_present'
        ") !== null;

        if ($exists) {
            $this->components->twoColumnDetail('Constraint zones_polygon_present', 'sudah ada');

            return;
        }

        // NOT VALID lalu VALIDATE: kalau masih ada zona tanpa geometry, yang
        // gagal adalah VALIDATE dengan pesan yang menyebut barisnya, bukan
        // resolver yang diam-diam mengembalikan nol zona berbulan-bulan
        // kemudian.
        DB::statement('
            ALTER TABLE zones ADD CONSTRAINT zones_polygon_present
            CHECK (polygon IS NOT NULL) NOT VALID
        ');
        DB::statement('ALTER TABLE zones VALIDATE CONSTRAINT zones_polygon_present');

        $this->components->twoColumnDetail('Constraint zones_polygon_present', '<fg=green>terpasang</>');
    }
}
