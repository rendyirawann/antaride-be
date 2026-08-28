<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Driver, kendaraan, dokumen KYC, dan sesi kerja.
 *
 * Driver selalu punya baris di `users` juga; tabel ini menyimpan yang khusus
 * driver. Pemisahan itu penting karena satu orang bisa jadi penumpang dan
 * driver sekaligus, dan riwayat order-nya sebagai penumpang tidak boleh
 * tercampur dengan riwayatnya sebagai driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createDrivers();
        $this->createDriverDocuments();
        $this->createVehicles();
        $this->createDriverServiceEligibility();
        $this->createDriverSessions();
        $this->createDriverViolations();
    }

    // -------------------------------------------------------------------------

    private function createDrivers(): void
    {
        Schema::create('drivers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Satu user hanya boleh punya satu profil driver.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // NIK dienkripsi di lapisan model dan di-masking secara default.
            // Yang bisa melihat penuh hanya role dengan permission kyc.view_full,
            // dan setiap pembukaannya dicatat.
            //
            // Panjang kolom mengikuti hasil enkripsi, bukan 16 digit aslinya.
            $table->text('nik')->nullable();

            // Hash NIK untuk mendeteksi pendaftaran ganda tanpa perlu
            // mendekripsi seluruh tabel. Tanpa ini, memeriksa "apakah NIK ini
            // sudah terdaftar" berarti mendekripsi setiap baris.
            $table->string('nik_hash', 64)->nullable();

            $table->string('full_name', 120);
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();

            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            $table->string('status', 20)->default('draft');
            $table->text('rejection_note')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            // --- Metrik performa, di-update asinkron oleh job ---
            //
            // Disimpan sebagai kolom, bukan dihitung saat dibutuhkan, karena
            // matching memanggilnya untuk setiap kandidat pada setiap order.
            // Menghitung rata-rata rating dari tabel ratings di jalur matching
            // berarti agregasi di jalur terpanas sistem.
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->decimal('acceptance_rate', 5, 2)->default(100.00);
            $table->decimal('cancellation_rate', 5, 2)->default(0.00);
            $table->unsignedInteger('completed_orders')->default(0);

            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE drivers ADD CONSTRAINT drivers_status_check
            CHECK (status IN ('draft','pending_review','rejected','active','suspended','banned'))
        ");

        DB::statement('
            ALTER TABLE drivers ADD CONSTRAINT drivers_metrics_check
            CHECK (
                rating_avg >= 0 AND rating_avg <= 5
                AND acceptance_rate >= 0 AND acceptance_rate <= 100
                AND cancellation_rate >= 0 AND cancellation_rate <= 100
            )
        ');

        // NIK unik di antara yang terisi. Satu orang tidak boleh punya dua akun
        // driver, dan pemeriksaannya lewat hash supaya tidak perlu dekripsi.
        DB::statement('
            CREATE UNIQUE INDEX drivers_nik_hash_unique ON drivers (nik_hash)
            WHERE nik_hash IS NOT NULL
        ');

        // Antrean verifikasi dokumen: driver menunggu review, terlama dulu.
        DB::statement("
            CREATE INDEX drivers_review_queue ON drivers (created_at)
            WHERE status = 'pending_review'
        ");

        DB::statement('CREATE INDEX drivers_status_created ON drivers (status, created_at DESC)');
        DB::statement('CREATE INDEX drivers_name_trgm ON drivers USING gin (full_name gin_trgm_ops)');
    }

    // -------------------------------------------------------------------------

    private function createDriverDocuments(): void
    {
        Schema::create('driver_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);

            // Path di disk privat, bukan URL publik. Diakses hanya lewat signed
            // URL berumur pendek yang diterbitkan setelah pemeriksaan izin.
            $table->string('file_path', 500);
            $table->string('file_hash', 64)->nullable();

            // Nomor dokumen dienkripsi seperti NIK.
            $table->text('number')->nullable();
            $table->date('expires_at')->nullable();

            $table->string('status', 20)->default('pending');
            $table->text('reject_reason')->nullable();

            $table->foreignId('reviewed_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['driver_id', 'type']);
        });

        DB::statement("
            ALTER TABLE driver_documents ADD CONSTRAINT driver_documents_type_check
            CHECK (type IN ('ktp','sim','stnk','skck','selfie','bank_book','vaccine'))
        ");

        DB::statement("
            ALTER TABLE driver_documents ADD CONSTRAINT driver_documents_status_check
            CHECK (status IN ('pending','approved','rejected'))
        ");

        // Antrean kerja verifikator: dokumen menunggu review, terlama dulu.
        DB::statement("
            CREATE INDEX driver_documents_queue ON driver_documents (created_at)
            WHERE status = 'pending'
        ");

        // Dokumen yang akan kadaluarsa, untuk job pengingat. SIM atau STNK yang
        // habis masa berlakunya membuat driver tidak sah beroperasi, dan
        // menemukannya setelah ada tilang sudah terlambat.
        DB::statement("
            CREATE INDEX driver_documents_expiring ON driver_documents (expires_at)
            WHERE status = 'approved' AND expires_at IS NOT NULL
        ");
    }

    // -------------------------------------------------------------------------

    private function createVehicles(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 30)->nullable();

            // Plat dinormalkan (huruf besar, tanpa spasi) sebelum disimpan,
            // supaya "BK 1234 AB" dan "bk1234ab" tidak jadi dua kendaraan.
            $table->string('plate_number', 15);

            $table->text('stnk_number')->nullable();
            $table->date('stnk_expires_at')->nullable();

            $table->unsignedTinyInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE vehicles ADD CONSTRAINT vehicles_type_check
            CHECK (type IN ('motorcycle','car_economy','car_premium','pickup'))
        ");

        DB::statement('
            ALTER TABLE vehicles ADD CONSTRAINT vehicles_year_check
            CHECK (year IS NULL OR (year >= 1980 AND year <= 2100))
        ');

        // Satu plat hanya boleh terdaftar pada satu kendaraan aktif. Kendaraan
        // lama yang dinonaktifkan tetap tersimpan untuk riwayat order, dan
        // platnya boleh dipakai kendaraan baru (misal driver ganti pemilik).
        DB::statement('
            CREATE UNIQUE INDEX vehicles_plate_active_unique ON vehicles (plate_number)
            WHERE is_active = true
        ');

        DB::statement('
            CREATE INDEX vehicles_driver_active ON vehicles (driver_id)
            WHERE is_active = true
        ');
    }

    // -------------------------------------------------------------------------

    /**
     * Layanan apa saja yang boleh diambil seorang driver.
     *
     * Driver motor bisa ambil ride_bike, food, dan send sekaligus. Driver mobil
     * hanya ride_car. Ini yang dipakai filter matching, jadi harus murah dibaca.
     */
    private function createDriverServiceEligibility(): void
    {
        Schema::create('driver_service_eligibility', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(true);

            // Driver boleh mematikan sendiri layanan tertentu (misal tidak mau
            // terima order makanan), terpisah dari kelayakan yang ditetapkan
            // admin. Dua flag, karena keduanya punya pemilik keputusan berbeda.
            $table->boolean('enabled_by_driver')->default(true);

            $table->timestamps();

            $table->unique(['driver_id', 'service_type_id']);
        });

        DB::statement('
            CREATE INDEX driver_eligibility_lookup
            ON driver_service_eligibility (service_type_id, driver_id)
            WHERE is_enabled = true AND enabled_by_driver = true
        ');
    }

    // -------------------------------------------------------------------------

    /**
     * Riwayat online/offline. Dipakai menghitung jam kerja dan insentif.
     */
    private function createDriverSessions(): void
    {
        Schema::create('driver_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('online_seconds')->default(0);
            $table->unsignedInteger('orders_taken')->default(0);
            $table->unsignedInteger('orders_completed')->default(0);

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();

            $table->timestamps();
        });

        // Satu driver hanya boleh punya satu sesi berjalan. Partial unique
        // index lagi: baris dengan ended_at terisi boleh berapa pun, yang
        // NULL hanya satu.
        DB::statement('
            CREATE UNIQUE INDEX driver_sessions_one_open ON driver_sessions (driver_id)
            WHERE ended_at IS NULL
        ');

        DB::statement('CREATE INDEX driver_sessions_driver_started ON driver_sessions (driver_id, started_at DESC)');
    }

    // -------------------------------------------------------------------------

    /**
     * Log pelanggaran driver: ping GPS palsu, cancel berlebihan, rating rendah.
     *
     * Dipisah dari audit_logs karena ini bagian dari berkas driver yang dibaca
     * tim ops saat memutuskan suspend, bukan jejak tindakan admin.
     */
    private function createDriverViolations(): void
    {
        Schema::create('driver_violations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('severity', 10)->default('low');
            $table->text('description')->nullable();
            $table->jsonb('evidence')->nullable();

            $table->unsignedBigInteger('order_id')->nullable();

            // Tindakan otomatis oleh sistem punya actor admin NULL.
            $table->foreignId('recorded_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            $table->string('action_taken', 30)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE driver_violations ADD CONSTRAINT driver_violations_type_check
            CHECK (type IN ('mock_gps','excessive_cancel','low_rating','speeding',
                            'route_deviation','fare_dispute','customer_complaint',
                            'document_expired','rooted_device','other'))
        ");

        DB::statement("
            ALTER TABLE driver_violations ADD CONSTRAINT driver_violations_severity_check
            CHECK (severity IN ('low','medium','high','critical'))
        ");

        DB::statement('CREATE INDEX driver_violations_driver ON driver_violations (driver_id, created_at DESC)');

        // Pelanggaran mock GPS dalam sehari, untuk auto suspend di ambang ke-5.
        DB::statement("
            CREATE INDEX driver_violations_mock_gps ON driver_violations (driver_id, created_at DESC)
            WHERE type = 'mock_gps'
        ");

        // Evidence JSONB perlu bisa dicari saat investigasi.
        DB::statement('CREATE INDEX driver_violations_evidence_gin ON driver_violations USING gin (evidence)');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('driver_violations');
        Schema::dropIfExists('driver_sessions');
        Schema::dropIfExists('driver_service_eligibility');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('driver_documents');
        Schema::dropIfExists('drivers');
    }
};
