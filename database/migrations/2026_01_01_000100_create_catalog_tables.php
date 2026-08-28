<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog layanan, zona operasional, tarif, dan surge.
 *
 * Semua nominal uang BIGINT dalam Rupiah utuh. Tidak ada FLOAT untuk uang,
 * pernah, sama sekali. Selisih satu rupiah yang tidak bisa dijelaskan akan
 * muncul, dan mencarinya memakan berhari-hari.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createServiceTypes();
        $this->createZones();
        $this->createPricingRules();
        $this->createSurgeRules();
    }

    // -------------------------------------------------------------------------

    private function createServiceTypes(): void
    {
        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->string('icon_url', 500)->nullable();

            // Jenis kendaraan yang boleh mengambil layanan ini.
            $table->string('vehicle_class', 20);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('requires_merchant')->default(false);
            $table->boolean('requires_multi_stop')->default(false);
            $table->boolean('requires_proof_photo')->default(false);

            $table->unsignedSmallInteger('max_stops')->default(1);
            $table->unsignedInteger('max_weight_gram')->nullable();

            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE service_types ADD CONSTRAINT service_types_code_check
            CHECK (code IN ('ride_bike','ride_car','food','send','mart','shop'))
        ");

        DB::statement("
            ALTER TABLE service_types ADD CONSTRAINT service_types_vehicle_class_check
            CHECK (vehicle_class IN ('motorcycle','car_economy','car_premium','pickup'))
        ");

        DB::statement('
            CREATE INDEX service_types_active ON service_types (sort_order)
            WHERE is_active = true
        ');
    }

    // -------------------------------------------------------------------------

    /**
     * Zona operasional. Menentukan tarif, surge, dan apakah sebuah titik
     * dilayani sama sekali.
     *
     * Polygon disimpan dua kali dengan peran berbeda:
     *
     *   polygon_geojson  JSONB, SUMBER KEBENARAN. Ini yang ditulis panel admin
     *                    saat ops menggambar zona di peta, dan yang dibaca
     *                    resolver 'native'. Selalu ada, tidak butuh PostGIS.
     *
     *   polygon          geometry(Polygon,4326), TURUNAN. Dibuat hanya kalau
     *                    PostGIS terpasang, diisi otomatis oleh trigger dari
     *                    polygon_geojson. Ini yang dipakai ST_Contains dengan
     *                    index GiST.
     *
     * Kenapa turunan dan bukan sumber kebenaran: supaya pengembangan tidak
     * terhalang saat PostGIS belum terpasang, dan supaya kedua resolver
     * dijamin membaca polygon yang sama. Trigger yang mengisi, bukan kode
     * aplikasi, jadi tidak mungkin drift walaupun ada yang menulis lewat SQL
     * langsung.
     */
    private function createZones(): void
    {
        Schema::create('zones', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->string('city', 80);
            $table->string('province', 80)->nullable();

            // GeoJSON Polygon lengkap: {"type":"Polygon","coordinates":[[...]]}
            $table->jsonb('polygon_geojson');

            // Bounding box hasil hitung, dipakai sebagai filter murah sebelum
            // uji point-in-polygon yang mahal. Resolver 'native' memakai ini
            // untuk membuang mayoritas zona dalam satu perbandingan angka.
            $table->decimal('min_lat', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lng', 10, 7);

            // Titik pusat, dipakai untuk memusatkan peta di panel admin.
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);

            $table->boolean('is_active')->default(true);

            // Zona yang lebih spesifik menang saat sebuah titik masuk ke dua
            // zona sekaligus (misal zona bandara di dalam zona kota).
            $table->unsignedSmallInteger('priority')->default(0);

            $table->timestamps();
        });

        /*
         * Tidak ada index bounding box di sini, dan itu disengaja.
         *
         * Sempat ada `zones_bbox` atas (min_lat, max_lat, min_lng, max_lng).
         * Dua alasan kenapa dihapus:
         *
         *   1. Tidak ada query yang memakainya. Resolver native memuat seluruh
         *      zona aktif lalu menguji titiknya di PHP; jalur PostGIS memakai
         *      GiST index atas kolom geometry. Keduanya tidak pernah menulis
         *      predikat bbox di SQL.
         *
         *   2. Bahkan kalau ada, B-tree tidak bisa melayaninya. Predikat empat
         *      rentang sekaligus hanya memanfaatkan kolom pertama untuk range
         *      scan, sisanya jadi filter — dengan puluhan zona, itu lebih mahal
         *      daripada sequential scan.
         *
         * Index yang tidak dipakai bukan sekadar netral: dia memperlambat setiap
         * tulis ke zones dan membuat pembaca kode menyangka ada jalur query yang
         * sebenarnya tidak pernah ada.
         */
        DB::statement('CREATE INDEX zones_city ON zones (city, is_active)');

        $this->addPostGisGeometry();
    }

    /**
     * Kolom geometry PostGIS beserta trigger pengisinya.
     *
     * Dipisah jadi method sendiri supaya bisa dipanggil ulang oleh perintah
     * `php artisan geo:enable-postgis` setelah ekstensinya dipasang, tanpa
     * perlu migrate:fresh.
     */
    private function addPostGisGeometry(): void
    {
        if (! $this->postGisInstalled()) {
            return;
        }

        DB::statement('ALTER TABLE zones ADD COLUMN polygon geometry(Polygon, 4326)');

        // GiST index inilah yang membuat ST_Contains cepat. Tanpa dia, setiap
        // quote akan menguji titik terhadap setiap polygon zona satu per satu.
        DB::statement('CREATE INDEX zones_polygon_gist ON zones USING gist (polygon)');

        // Trigger, bukan kode aplikasi. Siapa pun yang menulis zones setelah
        // ini, termasuk lewat psql langsung, akan mendapat kolom geometry yang
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

        DB::unprepared('
            CREATE TRIGGER zones_sync_polygon_trigger
            BEFORE INSERT OR UPDATE OF polygon_geojson ON zones
            FOR EACH ROW EXECUTE FUNCTION zones_sync_polygon();
        ');

        /*
         * Isi geometry untuk zona yang sudah ada.
         *
         * Trigger di atas hanya berjalan saat INSERT atau UPDATE. Kalau PostGIS
         * dipasang SETELAH database di-seed — urutan yang paling wajar terjadi,
         * karena migrate jalan dulu lalu ekstensinya menyusul — setiap zona yang
         * sudah tersimpan akan punya polygon NULL.
         *
         * Akibatnya diam-diam salah, bukan error: ST_Covers(NULL, titik)
         * menghasilkan NULL, WHERE membuangnya, dan resolver menyimpulkan
         * "di luar area layanan" untuk SELURUH kota. Order berhenti bisa dibuat
         * dan lognya tidak menunjukkan apa pun yang rusak.
         */
        DB::statement('
            UPDATE zones
            SET polygon = ST_SetSRID(ST_GeomFromGeoJSON(polygon_geojson::text), 4326)
            WHERE polygon IS NULL
        ');

        // Setelah backfill, zona tanpa geometry adalah kondisi yang tidak boleh
        // ada. Constraint ini yang mengubah kegagalan senyap di atas menjadi
        // error yang kelihatan pada saat penyebabnya masih bisa ditelusuri.
        DB::statement('
            ALTER TABLE zones ADD CONSTRAINT zones_polygon_present
            CHECK (polygon IS NOT NULL) NOT VALID
        ');
        DB::statement('ALTER TABLE zones VALIDATE CONSTRAINT zones_polygon_present');
    }

    private function postGisInstalled(): bool
    {
        return DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'postgis'") !== null;
    }

    // -------------------------------------------------------------------------

    /**
     * Tarif. Tabel ini APPEND-MOSTLY: tarif lama tidak pernah dihapus atau
     * ditimpa, cukup diberi effective_until.
     *
     * Alasannya bukan kerapian arsip. Kalau ada sengketa ongkos tiga bulan
     * lalu, kamu harus bisa menjelaskan angka yang keluar saat itu. Tarif yang
     * ditimpa membuat pertanyaan itu tidak terjawab selamanya.
     */
    private function createPricingRules(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();

            // NULL berarti tarif default yang berlaku untuk semua zona yang
            // tidak punya tarif khusus.
            $table->foreignId('zone_id')->nullable()->constrained()->restrictOnDelete();

            // --- Komponen tarif, semua BIGINT Rupiah utuh ---
            $table->bigInteger('base_fare');           // tarif buka pintu
            $table->bigInteger('per_km');
            $table->bigInteger('per_minute');          // biaya waktu tempuh
            $table->bigInteger('minimum_fare');
            $table->unsignedInteger('free_distance_m')->default(0);
            $table->bigInteger('platform_fee')->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);

            // Batas tarif yang diatur Kementerian Perhubungan. Hasil hitung
            // selalu diklem ke rentang ini sebelum ditampilkan.
            $table->bigInteger('min_fare_regulated')->nullable();
            $table->bigInteger('max_fare_regulated')->nullable();

            // Biaya tambahan khusus vertikal tertentu.
            $table->bigInteger('packaging_fee')->default(0);
            $table->bigInteger('insurance_fee')->default(0);

            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);

            // Setiap perubahan tarif melewati approval dua tahap, dan ini
            // menunjuk ke pengajuannya. Kolomnya nullable karena baris hasil
            // seeder awal tidak punya pengajuan.
            $table->unsignedBigInteger('approval_request_id')->nullable();

            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        // Nilai uang tidak boleh negatif. Komisi harus di rentang 0-100.
        DB::statement('
            ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_amounts_check
            CHECK (
                base_fare >= 0 AND per_km >= 0 AND per_minute >= 0
                AND minimum_fare >= 0 AND platform_fee >= 0
                AND commission_percent >= 0 AND commission_percent <= 100
            )
        ');

        // Rentang berlaku harus masuk akal.
        DB::statement('
            ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_period_check
            CHECK (effective_until IS NULL OR effective_until > effective_from)
        ');

        // Batas regulasi harus konsisten satu sama lain.
        DB::statement('
            ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_regulated_check
            CHECK (
                min_fare_regulated IS NULL
                OR max_fare_regulated IS NULL
                OR max_fare_regulated >= min_fare_regulated
            )
        ');

        /*
         * Inti dari tabel ini.
         *
         * Exclusion constraint mencegah dua tarif AKTIF dengan periode yang
         * bertumpang tindih untuk pasangan (service_type, zone) yang sama.
         * Ini yang membuat pertanyaan "berapa tarif ride_bike di zona Medan
         * Kota pada tanggal X" selalu punya tepat satu jawaban.
         *
         * MySQL tidak punya padanan untuk ini. Di sana, pencegahannya hanya
         * bisa berupa kode aplikasi yang disiplin, dan yang terjadi di lapangan
         * adalah admin mengubah tarif tanpa mengakhiri yang lama, lalu harga
         * yang keluar bergantung pada urutan baris yang dikembalikan query.
         *
         * COALESCE dipakai karena zone_id NULL berarti "tarif default", dan
         * dua tarif default yang bertumpang tindih juga harus ditolak. Tanpa
         * COALESCE, NULL tidak pernah sama dengan NULL dan constraint-nya
         * diam-diam tidak berlaku untuk baris default.
         *
         * tsrange, BUKAN tstzrange. Kolom effective_* bertipe `timestamp
         * without time zone` (konvensi Laravel, dengan koneksi dipatok UTC).
         * tstzrange akan memaksa cast ke timestamptz, dan cast itu hanya STABLE
         * karena hasilnya bergantung pada setelan TimeZone sesi. PostgreSQL
         * menolak fungsi non-IMMUTABLE di dalam index expression, jadi
         * constraint-nya gagal dibuat sama sekali.
         */
        DB::statement('
            ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_no_overlap
            EXCLUDE USING gist (
                service_type_id WITH =,
                COALESCE(zone_id, 0) WITH =,
                tsrange(effective_from, effective_until) WITH &&
            ) WHERE (is_active = true)
        ');

        // Pencarian tarif yang berlaku saat ini untuk satu layanan dan zona.
        DB::statement('
            CREATE INDEX pricing_rules_lookup
            ON pricing_rules (service_type_id, zone_id, effective_from DESC)
            WHERE is_active = true
        ');
    }

    // -------------------------------------------------------------------------

    private function createSurgeRules(): void
    {
        Schema::create('surge_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();

            $table->string('trigger_type', 20);

            // NULL berarti berlaku setiap hari.
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->decimal('multiplier', 3, 2);

            // Rasio order berbanding driver tersedia yang mengaktifkan surge
            // otomatis. Hanya dipakai kalau trigger_type = 'demand_ratio'.
            $table->decimal('demand_threshold', 5, 2)->nullable();

            // Surge manual dari tombol darurat tim ops punya masa berlaku,
            // supaya tidak ada yang lupa mematikannya.
            $table->timestamp('manual_until')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE surge_rules ADD CONSTRAINT surge_rules_trigger_check
            CHECK (trigger_type IN ('schedule','demand_ratio','manual'))
        ");

        // Batas atas surge. Angka di atas 3x bukan lagi penyesuaian permintaan,
        // itu keluhan yang akan sampai ke media sosial.
        DB::statement('
            ALTER TABLE surge_rules ADD CONSTRAINT surge_rules_multiplier_check
            CHECK (multiplier >= 1.00 AND multiplier <= 3.00)
        ');

        DB::statement('
            ALTER TABLE surge_rules ADD CONSTRAINT surge_rules_dow_check
            CHECK (day_of_week IS NULL OR day_of_week BETWEEN 0 AND 6)
        ');

        // Aturan berjadwal wajib punya jam; aturan rasio wajib punya ambang.
        DB::statement("
            ALTER TABLE surge_rules ADD CONSTRAINT surge_rules_shape_check
            CHECK (
                (trigger_type = 'schedule' AND start_time IS NOT NULL AND end_time IS NOT NULL)
                OR (trigger_type = 'demand_ratio' AND demand_threshold IS NOT NULL)
                OR (trigger_type = 'manual')
            )
        ");

        DB::statement('
            CREATE INDEX surge_rules_lookup ON surge_rules (zone_id, service_type_id)
            WHERE is_active = true
        ');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('surge_rules');
        Schema::dropIfExists('pricing_rules');

        if ($this->postGisInstalled()) {
            DB::unprepared('DROP TRIGGER IF EXISTS zones_sync_polygon_trigger ON zones');
            DB::unprepared('DROP FUNCTION IF EXISTS zones_sync_polygon()');
        }

        Schema::dropIfExists('zones');
        Schema::dropIfExists('service_types');
    }
};
