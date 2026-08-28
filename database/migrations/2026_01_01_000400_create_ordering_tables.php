<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order: inti seluruh sistem.
 *
 * Pola yang dipakai: satu tabel `orders` untuk data umum, plus tabel detail per
 * vertikal. Ini lebih sehat daripada satu tabel raksasa dengan 80 kolom
 * nullable yang tidak ada yang berani sentuh setelah enam bulan.
 *
 * Semua nominal BIGINT Rupiah utuh, dibekukan sebagai snapshot saat order
 * dibuat. Tarif boleh berubah besok; ongkos order yang sudah jalan tidak.
 */
return new class extends Migration
{
    /**
     * Status yang menandai order masih berjalan. Dipakai partial unique index
     * di bawah, dan HARUS sama dengan daftar di
     * App\Domain\Ordering\Enums\OrderStatus::activeStatuses().
     *
     * Kalau kedua daftar berbeda, driver bisa memegang dua order sekaligus
     * tanpa satu pun error muncul. Ada test yang membandingkan keduanya.
     */
    private const ACTIVE_STATUSES = [
        'accepted',
        'driver_arriving',
        'driver_arrived',
        'in_progress',
    ];

    public function up(): void
    {
        $this->createCancellationReasons();
        $this->createOrders();
        $this->createOrderStops();
        $this->createOrderStatusLogs();
        $this->createOrderOffers();
        $this->createOrderChats();
        $this->createRatings();
    }

    // -------------------------------------------------------------------------

    private function createCancellationReasons(): void
    {
        Schema::create('cancellation_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 10);
            $table->string('text', 255);
            $table->string('code', 40)->unique();

            $table->boolean('charges_fee')->default(false);
            $table->boolean('affects_driver_score')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE cancellation_reasons ADD CONSTRAINT cancellation_reasons_actor_check
            CHECK (actor_type IN ('user','driver','admin','system'))
        ");
    }

    // -------------------------------------------------------------------------

    private function createOrders(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Format RD-20260827-000123. Dipakai CS saat menerima telepon,
            // jadi harus bisa dibacakan lewat suara tanpa ambigu.
            $table->string('order_number', 24)->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 32)->default('created');
            $table->string('payment_method', 10);
            $table->string('payment_status', 12)->default('unpaid');

            // --- Snapshot harga, dibekukan saat order dibuat ---
            $table->unsignedInteger('distance_m');
            $table->unsignedInteger('duration_s');

            $table->bigInteger('base_fare');
            $table->bigInteger('distance_fare');
            $table->bigInteger('time_fare')->default(0);
            $table->decimal('surge_multiplier', 3, 2)->default(1.00);
            $table->bigInteger('surge_amount')->default(0);

            /*
             * Penyesuaian karena tarif maksimum yang diatur pemerintah.
             *
             * Hampir selalu nol, dan negatif saat ongkos hasil hitung menembus
             * batas atas Permenhub untuk zona tersebut. Contoh nyata: 50 km
             * menghasilkan ongkos jarak Rp 105.600 sementara batasnya
             * Rp 100.000, jadi kolom ini berisi -5.600.
             *
             * Kenapa disimpan sebagai baris tersendiri dan bukan diserap ke
             * distance_fare: kalau diserap, tarif per-km yang tampil di struk
             * tidak akan cocok dengan tarif yang dipublikasikan, dan penumpang
             * yang menghitung sendiri akan menyimpulkan kami memanipulasi
             * angka. Lebih baik pemotongannya terlihat sebagai apa adanya.
             */
            $table->bigInteger('regulatory_adjustment')->default(0);

            $table->bigInteger('platform_fee')->default(0);
            $table->bigInteger('service_fee')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('promo_id')->nullable();

            $table->bigInteger('total_fare');        // yang dibayar user
            $table->bigInteger('driver_earning');    // yang diterima driver
            $table->bigInteger('commission_amount'); // potongan platform

            // Tarif yang dipakai, supaya sengketa ongkos bisa dilacak ke aturan
            // persisnya, bukan ke aturan yang berlaku sekarang.
            $table->foreignId('pricing_rule_id')->nullable()
                ->constrained('pricing_rules')->nullOnDelete();

            // --- Lokasi ---
            $table->text('pickup_address');
            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_note', 255)->nullable();

            $table->text('dest_address')->nullable();
            $table->decimal('dest_lat', 10, 7)->nullable();
            $table->decimal('dest_lng', 10, 7)->nullable();

            // Kode 4 digit yang disebut penumpang ke driver. Tanpa ini, driver
            // bisa mengaku sudah menjemput padahal belum, dan itu cara favorit
            // untuk memancing cancellation fee.
            $table->char('pickup_code', 4)->nullable();

            // Polyline hasil OSRM saat estimasi, dan polyline sungguhan dari
            // GPS yang diisi saat order selesai. Keduanya ditumpuk di peta
            // panel admin untuk menjawab "kenapa ongkosnya beda dari estimasi".
            $table->text('route_polyline')->nullable();
            $table->text('actual_polyline')->nullable();
            $table->unsignedInteger('actual_distance_m')->nullable();

            // Ditandai kalau jarak aktual jauh berbeda dari estimasi. Order
            // seperti ini tidak di-settle otomatis.
            $table->boolean('needs_fare_review')->default(false);

            // --- Cap waktu setiap transisi ---
            $table->timestamp('requested_at');
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('cancelled_by', 10)->nullable();
            $table->foreignId('cancellation_reason_id')->nullable()
                ->constrained('cancellation_reasons')->nullOnDelete();
            $table->text('cancellation_note')->nullable();
            $table->bigInteger('cancellation_fee')->default(0);

            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->timestamps();
        });

        // --- CHECK constraint ---

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT orders_status_check
            CHECK (status IN ('created','searching','accepted','driver_arriving',
                              'driver_arrived','in_progress','completed',
                              'cancelled','no_driver','expired'))
        ");

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check
            CHECK (payment_method IN ('cash','wallet','va','qris','card'))
        ");

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check
            CHECK (payment_status IN ('unpaid','held','paid','refunded','failed'))
        ");

        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_check
            CHECK (cancelled_by IS NULL OR cancelled_by IN ('user','driver','system','admin'))
        ");

        /*
         * Uang tidak boleh negatif, dan surge harus dalam rentang yang wajar.
         *
         * SELURUH sebelas kolom uang disebut di sini, bukan sekadar yang
         * "penting". Sempat hanya lima yang ditutup, dan enam sisanya —
         * base_fare, distance_fare, time_fare, surge_amount, platform_fee,
         * service_fee — boleh negatif. Itu bukan bug teoretis: satu tarif yang
         * salah tanda di panel admin akan menghasilkan komponen negatif yang
         * lolos ke database, lalu muncul di struk penumpang sebagai potongan
         * yang tidak pernah dijanjikan siapa pun.
         *
         * regulatory_adjustment adalah SATU-SATUNYA kolom uang yang boleh
         * negatif, dan justru hampir selalu negatif — dia mencatat pemotongan
         * saat ongkos hasil hitung menembus tarif maksimum yang diatur
         * pemerintah. Lihat FareCalculator langkah 4.
         */
        DB::statement('
            ALTER TABLE orders ADD CONSTRAINT orders_money_check
            CHECK (
                base_fare >= 0 AND distance_fare >= 0 AND time_fare >= 0
                AND surge_amount >= 0 AND platform_fee >= 0 AND service_fee >= 0
                AND total_fare >= 0 AND driver_earning >= 0 AND commission_amount >= 0
                AND discount_amount >= 0 AND cancellation_fee >= 0
                AND surge_multiplier >= 1.00 AND surge_multiplier <= 3.00
            )
        ');

        /*
         * Rincian ongkos harus benar-benar menjumlah ke total.
         *
         * Ini constraint yang paling banyak menangkap kesalahan saat rumus
         * tarif diubah. Tanpa dia, struk yang ditampilkan ke penumpang bisa
         * memuat enam angka yang tidak berjumlah ke angka yang dia bayar, dan
         * itu tipe keluhan yang tidak bisa dijawab tanpa membongkar kode.
         *
         * Urutannya mengikuti FareCalculator: komponen transport, lalu surge,
         * lalu penyesuaian regulasi, lalu biaya, lalu diskon.
         */
        DB::statement('
            ALTER TABLE orders ADD CONSTRAINT orders_breakdown_sums_check
            CHECK (
                total_fare = base_fare + distance_fare + time_fare
                          + surge_amount + regulatory_adjustment
                          + platform_fee + service_fee
                          - discount_amount
            )
        ');

        /*
         * Pembagian uang harus utuh.
         *
         * Yang diterima driver ditambah potongan platform tidak boleh melebihi
         * yang dibayar penumpang. Kalau sampai bisa, platform membayar dari
         * kantong sendiri tanpa ada yang tahu sampai rekonsiliasi bulanan.
         *
         * Ditegakkan database, bukan hanya kode, karena ini invariant yang
         * kalau bocor tidak bisa diperbaiki: ledger sudah tercatat.
         */
        DB::statement('
            ALTER TABLE orders ADD CONSTRAINT orders_split_check
            CHECK (driver_earning + commission_amount <= total_fare + discount_amount)
        ');

        // Order yang selesai wajib punya driver dan cap waktu selesai.
        DB::statement("
            ALTER TABLE orders ADD CONSTRAINT orders_completed_shape_check
            CHECK (
                status <> 'completed'
                OR (driver_id IS NOT NULL AND completed_at IS NOT NULL)
            )
        ");

        /*
         * ==================================================================
         *  INTI: satu driver hanya boleh punya SATU order berjalan.
         * ==================================================================
         *
         * Blueprint menulis, untuk MySQL:
         *
         *   "MySQL tidak punya partial unique index, jadi constraint driver
         *    hanya boleh punya 1 order berjalan ditegakkan lewat tabel
         *    driver_active_orders. INSERT saat accept, DELETE saat
         *    complete/cancel."
         *
         * Di PostgreSQL tabel itu tidak diperlukan. Satu index ini menegakkan
         * invariant yang sama, langsung pada tabel yang memegang kebenarannya.
         *
         * Yang hilang bersama tabel bayangan itu: enam tempat berbeda yang
         * harus ingat INSERT dan DELETE, dan setiap jalur keluar order
         * (selesai, batal user, batal driver, batal admin, timeout, kill
         * switch) yang harus ingat membersihkannya. Satu jalur yang lupa, dan
         * driver itu tidak akan pernah menerima order lagi sampai ada yang
         * menghapus barisnya lewat phpMyAdmin.
         *
         * Sekarang invariantnya tidak bisa bocor: dua order berjalan untuk
         * driver yang sama akan ditolak database, apa pun jalur kodenya.
         */
        $activeList = "'".implode("','", self::ACTIVE_STATUSES)."'";

        DB::statement("
            CREATE UNIQUE INDEX orders_one_active_per_driver ON orders (driver_id)
            WHERE driver_id IS NOT NULL AND status IN ({$activeList})
        ");

        // Aturan yang sama untuk penumpang: satu order aktif sekaligus,
        // termasuk yang masih mencari driver.
        DB::statement("
            CREATE UNIQUE INDEX orders_one_active_per_user ON orders (user_id)
            WHERE status IN ('created','searching',{$activeList})
        ");

        // --- Index untuk pola query yang nyata ---

        // Riwayat order penumpang di app.
        DB::statement('CREATE INDEX orders_user_created ON orders (user_id, created_at DESC)');

        // Riwayat dan pendapatan driver.
        DB::statement('CREATE INDEX orders_driver_created ON orders (driver_id, created_at DESC)');

        // Daftar order di panel admin, difilter status.
        DB::statement('CREATE INDEX orders_status_created ON orders (status, created_at DESC)');

        // Dashboard dan metrik per zona per layanan.
        DB::statement('CREATE INDEX orders_zone_service_created ON orders (zone_id, service_type_id, created_at DESC)');

        // Pencarian order berdasarkan nomor, saat CS menerima telepon.
        DB::statement('CREATE INDEX orders_number_trgm ON orders USING gin (order_number gin_trgm_ops)');

        // Order yang masih mencari driver lebih dari 60 detik: ini yang paling
        // sering dilihat tim ops di live map.
        DB::statement("
            CREATE INDEX orders_searching ON orders (requested_at)
            WHERE status = 'searching'
        ");

        // Order yang perlu review ongkos sebelum di-settle.
        DB::statement('
            CREATE INDEX orders_fare_review ON orders (completed_at)
            WHERE needs_fare_review = true
        ');
    }

    // -------------------------------------------------------------------------

    private function createOrderStops(): void
    {
        Schema::create('order_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('sequence');
            $table->string('type', 10);

            $table->text('address');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->string('proof_photo_path', 500)->nullable();
            $table->string('receiver_name', 120)->nullable();

            $table->timestamps();

            $table->unique(['order_id', 'sequence']);
        });

        DB::statement("
            ALTER TABLE order_stops ADD CONSTRAINT order_stops_type_check
            CHECK (type IN ('pickup','dropoff'))
        ");
    }

    // -------------------------------------------------------------------------

    /**
     * Riwayat transisi status. APPEND ONLY: tidak ada UPDATE, tidak ada DELETE
     * di jalur aplikasi.
     *
     * Tabel biasa, TIDAK dipartisi. Ini keputusan sadar.
     *
     * Partisi memang mempercepat retensi (DROP PARTITION selesai dalam
     * milidetik) dan memberi partition pruning untuk query bertanggal. Tapi
     * harganya adalah partisi yang harus selalu ada sebelum barisnya datang.
     * Kalau sampai tidak ada, INSERT gagal, dan karena transisi status jalan
     * di dalam transaksi yang sama dengan perubahan order, yang rollback bukan
     * cuma log: order ikut macet dan penumpang menunggu tanpa sebab yang
     * terlihat. Itu satu mekanisme lagi yang harus dijaga hidup, untuk tabel
     * yang pada volume Fase 1 hanya tumbuh sekitar 4.000 baris per hari.
     *
     * Retensi dijalankan `php artisan antaride:prune-logs` dengan penghapusan
     * bertahap per potongan, dijadwalkan di jam sepi. Lebih lambat dari DROP
     * PARTITION, dan itu tidak masalah karena tidak ada yang menunggunya.
     */
    private function createOrderStatusLogs(): void
    {
        Schema::create('order_status_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id')->nullable();

            // Posisi saat transisi terjadi. Ini yang menjawab "driver benar-benar
            // ada di titik jemput saat menekan tombol sampai, atau tidak".
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestamp('created_at')->nullable();
        });

        DB::statement("
            ALTER TABLE order_status_logs ADD CONSTRAINT order_status_logs_actor_check
            CHECK (actor_type IN ('user','driver','system','admin'))
        ");

        // Timeline order di halaman detail: pola query yang paling sering
        // dipakai di seluruh panel admin.
        DB::statement('CREATE INDEX order_status_logs_order ON order_status_logs (order_id, created_at)');

        // Retensi dan investigasi bertanggal.
        DB::statement('CREATE INDEX order_status_logs_created ON order_status_logs (created_at)');

        DB::statement('CREATE INDEX order_status_logs_metadata_gin ON order_status_logs USING gin (metadata)');
    }

    // -------------------------------------------------------------------------

    /**
     * Riwayat penawaran ke driver. Penting untuk audit matching.
     *
     * Ini tabel yang jarang dibangun orang, dan justru yang paling menjawab
     * pertanyaan "kenapa penumpang saya menunggu 8 menit". Tanpa dia, tidak ada
     * cara mengetahui siapa yang ditawari, siapa menolak, dan siapa yang
     * membiarkan penawaran kadaluarsa.
     */
    private function createOrderOffers(): void
    {
        Schema::create('order_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            // Gelombang keberapa penawaran ini dikirim.
            $table->unsignedTinyInteger('wave');

            $table->unsignedInteger('distance_to_pickup_m');
            $table->decimal('score', 6, 3);

            // Rincian skor disimpan supaya keputusan matching bisa dijelaskan
            // ulang. Saat driver mengeluh "kenapa saya tidak pernah dapat
            // order", ini yang menjawabnya dengan angka.
            $table->jsonb('score_breakdown')->nullable();

            $table->timestamp('offered_at');
            $table->timestamp('expires_at');

            $table->string('response', 12)->default('pending');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // Satu driver hanya ditawari satu kali per order.
            $table->unique(['order_id', 'driver_id']);
        });

        /*
         * 'lost' DIBEDAKAN dari 'timeout', dan itu bukan detail kosmetik.
         *
         * Keduanya berarti driver tidak mendapatkan order, tapi penyebabnya
         * berlawanan:
         *
         *   timeout   driver membiarkan penawarannya habis tanpa menjawab
         *   lost      driver lain lebih cepat menekan terima
         *
         * acceptance_rate dihitung dari kolom ini. Kalau 'lost' ikut dihitung
         * sebagai tidak-merespons, driver yang aktif justru dirugikan: makin
         * sering dia ditawari bersama driver lain, makin sering dia kalah
         * balapan, makin turun acceptance_rate-nya — dan skor matching-nya ikut
         * turun, sehingga dia makin jarang ditawari.
         *
         * Itu lingkaran yang menghukum driver karena kalah adu cepat, bukan
         * karena mengabaikan pekerjaan.
         */
        DB::statement("
            ALTER TABLE order_offers ADD CONSTRAINT order_offers_response_check
            CHECK (response IN ('pending','accepted','rejected','timeout','lost','cancelled'))
        ");

        // Hanya SATU penawaran per order yang boleh diterima. Ini jaring
        // terakhir di database kalau lock Redis gagal, misalnya karena Redis
        // baru restart di tengah gelombang penawaran.
        DB::statement("
            CREATE UNIQUE INDEX order_offers_one_accepted ON order_offers (order_id)
            WHERE response = 'accepted'
        ");

        // Penawaran yang menunggu jawaban, untuk job timeout.
        DB::statement("
            CREATE INDEX order_offers_pending ON order_offers (expires_at)
            WHERE response = 'pending'
        ");

        // Perhitungan acceptance_rate driver.
        DB::statement('CREATE INDEX order_offers_driver_created ON order_offers (driver_id, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    private function createOrderChats(): void
    {
        Schema::create('order_chats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('sender_type', 10);
            $table->unsignedBigInteger('sender_id');

            $table->text('message');

            // Pesan template ("Saya sudah di depan") dipisah dari pesan bebas,
            // karena hanya yang bebas perlu moderasi.
            $table->boolean('is_template')->default(false);

            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement("
            ALTER TABLE order_chats ADD CONSTRAINT order_chats_sender_check
            CHECK (sender_type IN ('user','driver','admin','system'))
        ");

        DB::statement('CREATE INDEX order_chats_order ON order_chats (order_id, created_at)');
    }

    // -------------------------------------------------------------------------

    private function createRatings(): void
    {
        Schema::create('ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('rater_type', 10);
            $table->unsignedBigInteger('rater_id');
            $table->string('ratee_type', 10);
            $table->unsignedBigInteger('ratee_id');

            $table->unsignedTinyInteger('score');
            $table->jsonb('tags')->nullable();
            $table->text('comment')->nullable();

            // Komentar yang melanggar disembunyikan, tidak dihapus, supaya
            // keputusan moderasi bisa ditinjau.
            $table->boolean('is_hidden')->default(false);
            $table->foreignId('hidden_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            $table->timestamps();

            // Satu pihak hanya boleh menilai satu kali per order. Penumpang dan
            // driver masing-masing dapat satu kesempatan.
            $table->unique(['order_id', 'rater_type']);
        });

        DB::statement("
            ALTER TABLE ratings ADD CONSTRAINT ratings_rater_check
            CHECK (rater_type IN ('user','driver'))
        ");

        DB::statement("
            ALTER TABLE ratings ADD CONSTRAINT ratings_ratee_check
            CHECK (ratee_type IN ('user','driver','merchant'))
        ");

        DB::statement('
            ALTER TABLE ratings ADD CONSTRAINT ratings_score_check
            CHECK (score BETWEEN 1 AND 5)
        ');

        // Perhitungan rating_avg per driver/merchant.
        DB::statement('
            CREATE INDEX ratings_ratee ON ratings (ratee_type, ratee_id, created_at DESC)
            WHERE is_hidden = false
        ');

        // Rating rendah yang perlu ditindak tim ops.
        DB::statement('
            CREATE INDEX ratings_low_scores ON ratings (ratee_type, ratee_id, created_at DESC)
            WHERE score <= 2 AND is_hidden = false
        ');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('order_chats');
        Schema::dropIfExists('order_offers');
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('order_stops');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cancellation_reasons');
    }
};
