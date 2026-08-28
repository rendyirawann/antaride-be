<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel agregat untuk dashboard.
 *
 * Alasan tabel ini ada: query seperti "GMV bulan ini per zona per layanan" pada
 * tabel `orders` mentah akan membebani database yang sedang melayani order
 * masuk. Satu staf yang membuka dashboard tidak boleh bisa memperlambat
 * penerimaan order.
 *
 * Dashboard HANYA membaca tabel ini. Angka yang benar-benar butuh real-time
 * (order berjalan sekarang, driver online sekarang) diambil dari Redis, bukan
 * dari sini dan bukan dari orders.
 *
 * Diisi dengan pola upsert supaya job-nya bisa dijalankan ulang tanpa
 * menghasilkan duplikasi. Ini penting: job agregasi akan gagal di tengah jalan
 * suatu hari, dan yang harus bisa dilakukan adalah menjalankannya lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createMetricsDaily();
        $this->createMetricsHourly();
        $this->createDriverDailyMetrics();
    }

    // -------------------------------------------------------------------------

    private function createMetricsDaily(): void
    {
        Schema::create('metrics_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('date');

            // NULL berarti agregat seluruh zona / seluruh layanan. Baris total
            // disimpan bersama rinciannya supaya dashboard tidak perlu
            // menjumlahkan puluhan baris di sisi aplikasi.
            $table->foreignId('zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->cascadeOnDelete();

            // --- Volume ---
            $table->unsignedInteger('orders_created')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);
            $table->unsignedInteger('orders_cancelled')->default(0);
            $table->unsignedInteger('orders_no_driver')->default(0);

            // --- Uang, semua BIGINT Rupiah utuh ---
            $table->bigInteger('gmv')->default(0);
            $table->bigInteger('driver_earning')->default(0);
            $table->bigInteger('commission')->default(0);
            $table->bigInteger('discount_cost')->default(0);
            $table->bigInteger('surge_revenue')->default(0);

            // --- Kualitas layanan ---
            $table->unsignedInteger('avg_wait_seconds')->default(0);
            $table->unsignedInteger('avg_trip_seconds')->default(0);

            // Persentil, bukan hanya rata-rata. Rata-rata waktu tunggu 4 menit
            // terdengar baik sampai kamu tahu p90-nya 18 menit, dan yang pindah
            // ke aplikasi lain adalah yang mengalami p90.
            $table->unsignedInteger('p50_wait_seconds')->default(0);
            $table->unsignedInteger('p90_wait_seconds')->default(0);

            // --- Likuiditas dua sisi ---
            $table->unsignedInteger('unique_customers')->default(0);
            $table->unsignedInteger('active_drivers')->default(0);
            $table->decimal('avg_acceptance_rate', 5, 2)->default(0);

            $table->timestamps();

        });

        /*
         * =====================================================================
         *  KENAPA UNIQUE BIASA TIDAK CUKUP, DAN KENAPA BUKAN INDEX PARSIAL
         * =====================================================================
         *  zone_id dan service_type_id nullable, dan NULL di sini punya arti:
         *  "semua zona" / "semua layanan". Baris agregat total adalah
         *  (date, NULL, NULL).
         *
         *  UNIQUE biasa tidak menutup baris itu, karena PostgreSQL menganggap
         *  NULL tidak sama dengan NULL. Job agregasi yang jalan dua kali akan
         *  membuat DUA baris total untuk hari yang sama, dan dashboard
         *  menampilkan GMV dua kali lipat — angka yang salah tapi kelihatan
         *  masuk akal, jadi tidak ada yang curiga sampai ditagih.
         *
         *  Cara lamanya: tiga index parsial untuk tiga kombinasi NULL. Itu
         *  ditinggalkan karena kelengkapannya bergantung pada ketelitian
         *  manusia — dan memang terbukti gagal: metrics_hourly hanya punya dua
         *  dari tiga, sehingga baris per-layanan di sana bisa dobel sementara
         *  metrics_daily aman. Bug yang bentuknya "satu index lupa ditulis"
         *  tidak bisa dicegah oleh review.
         *
         *  NULLS NOT DISTINCT (PostgreSQL 15+, di sini 18) membuat NULL
         *  dianggap sama dengan NULL untuk keperluan keunikan. Satu index
         *  menutup keempat kombinasi sekaligus, dan tidak ada kombinasi yang
         *  bisa lupa ditulis.
         * =====================================================================
         */
        DB::statement('
            CREATE UNIQUE INDEX metrics_daily_unique
            ON metrics_daily (date, zone_id, service_type_id)
            NULLS NOT DISTINCT
        ');

        // Grafik tren: rentang tanggal untuk satu zona dan layanan.
        DB::statement('CREATE INDEX metrics_daily_lookup ON metrics_daily (date DESC, zone_id, service_type_id)');
    }

    // -------------------------------------------------------------------------

    /**
     * Agregat per jam, untuk grafik intraday dan keputusan surge.
     *
     * `demand_supply_ratio` yang ada di sini adalah angka yang paling dilihat
     * tim ops: rasio order berbanding driver tersedia. Itu yang menentukan
     * apakah surge dinyalakan, dan itu yang menjelaskan kenapa order gagal
     * di jam tertentu.
     */
    private function createMetricsHourly(): void
    {
        Schema::create('metrics_hourly', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('datetime_hour');

            $table->foreignId('zone_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('orders_created')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);
            $table->unsignedInteger('orders_no_driver')->default(0);

            $table->decimal('drivers_online_avg', 8, 2)->default(0);
            $table->decimal('demand_supply_ratio', 6, 3)->default(0);
            $table->decimal('surge_applied_avg', 3, 2)->default(1.00);

            $table->unsignedInteger('avg_wait_seconds')->default(0);
            $table->bigInteger('gmv')->default(0);

            $table->timestamps();

        });

        // Alasan NULLS NOT DISTINCT ada di createDailyMetrics(). Tabel inilah
        // yang dulu kehilangan satu dari tiga index parsialnya.
        DB::statement('
            CREATE UNIQUE INDEX metrics_hourly_unique
            ON metrics_hourly (datetime_hour, zone_id, service_type_id)
            NULLS NOT DISTINCT
        ');

        DB::statement('CREATE INDEX metrics_hourly_lookup ON metrics_hourly (datetime_hour DESC, zone_id, service_type_id)');
    }

    // -------------------------------------------------------------------------

    /**
     * Performa harian per driver.
     *
     * Dipisah dari metrics_daily karena pemakaiannya berbeda: yang ini dibaca
     * satu baris pada satu waktu (halaman profil driver, perhitungan insentif),
     * bukan diagregasi untuk grafik.
     */
    private function createDriverDailyMetrics(): void
    {
        Schema::create('driver_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('online_seconds')->default(0);
            $table->unsignedInteger('offers_received')->default(0);
            $table->unsignedInteger('offers_accepted')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);
            $table->unsignedInteger('orders_cancelled')->default(0);

            $table->bigInteger('gross_earning')->default(0);
            $table->bigInteger('commission_paid')->default(0);
            $table->bigInteger('incentive_earned')->default(0);
            $table->bigInteger('net_earning')->default(0);

            $table->unsignedInteger('distance_m')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedSmallInteger('rating_count')->default(0);

            $table->timestamps();

            $table->unique(['date', 'driver_id']);
        });

        // Papan peringkat dan perhitungan insentif: driver paling produktif
        // pada satu hari.
        DB::statement('CREATE INDEX driver_daily_metrics_leaderboard ON driver_daily_metrics (date DESC, orders_completed DESC)');

        DB::statement('CREATE INDEX driver_daily_metrics_driver ON driver_daily_metrics (driver_id, date DESC)');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('driver_daily_metrics');
        Schema::dropIfExists('metrics_hourly');
        Schema::dropIfExists('metrics_daily');
    }
};
