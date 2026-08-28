<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas aktor eksternal: customer, driver, dan merchant owner.
 *
 * Semuanya punya baris di `users`. Perannya tidak ditentukan kolom di sini,
 * tapi oleh keberadaan baris di tabel `drivers` / `merchants`.
 *
 * Staf internal TIDAK ada di sini. Tabelnya terpisah (lihat migration admin),
 * supaya tidak ada satu pun jalur di alur registrasi customer yang bisa
 * berujung pada akun admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createUsers();
        $this->createUserDevices();
        $this->createUserAddresses();
        $this->createOtpRequests();
        $this->createSessions();
    }

    // -------------------------------------------------------------------------

    private function createUsers(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Nomor HP adalah identitas utama. Disimpan dalam format E.164
            // tanpa tanda plus (contoh: 6281234567890) supaya 0812..., +62812...,
            // dan 62812... tidak pernah jadi tiga akun berbeda.
            $table->string('phone', 20)->unique();

            // Dibuat sebagai varchar lalu di-ALTER jadi citext di bawah,
            // karena Laravel belum punya tipe kolom citext.
            $table->string('email', 190)->nullable();

            $table->string('name', 120);

            // Nullable karena login utama memakai OTP. Yang punya password
            // hanya user yang mendaftar lewat jalur lain.
            $table->string('password')->nullable();

            $table->string('photo_url', 500)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birth_date')->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamp('phone_verified_at')->nullable();

            $table->string('referral_code', 20)->nullable()->unique();
            $table->foreignId('referred_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->rememberToken();
            $table->timestamps();

            // Penghapusan akun menurut UU PDP harus benar-benar menghapus.
            // Kolom ini menandai permintaan hapus yang sedang menunggu masa
            // tunggu berakhir, bukan soft delete permanen.
            $table->timestamp('deletion_requested_at')->nullable();
        });

        // citext membuat perbandingan email case-insensitive di level tipe,
        // sehingga unique index di bawah benar-benar mencegah "Budi@mail.com"
        // dan "budi@mail.com" hidup sebagai dua akun berbeda. Tanpa ini,
        // pencegahannya bergantung pada setiap penulis query mengingat LOWER().
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');

        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_status_check
            CHECK (status IN ('active','suspended','banned','deleted'))
        ");

        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_gender_check
            CHECK (gender IS NULL OR gender IN ('male','female'))
        ");

        // Email unik hanya di antara yang terisi. Ini partial unique index,
        // yang tidak bisa dilakukan MySQL: ribuan user boleh punya email NULL,
        // tapi email yang terisi tetap wajib unik.
        DB::statement('
            CREATE UNIQUE INDEX users_email_unique ON users (email)
            WHERE email IS NOT NULL
        ');

        // Pencarian CS: nama atau nomor HP dengan kata di tengah. GIN trigram
        // membuat ILIKE %kata% tetap terindeks.
        DB::statement('CREATE INDEX users_name_trgm ON users USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX users_phone_trgm ON users USING gin (phone gin_trgm_ops)');

        // Daftar user di panel admin diurutkan terbaru dulu, difilter status.
        DB::statement('CREATE INDEX users_status_created ON users (status, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    private function createUserDevices(): void
    {
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('device_id', 128);
            $table->string('platform', 10);
            $table->string('fcm_token', 500)->nullable();

            $table->string('app_version', 20)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('device_model', 80)->nullable();

            // Device yang di-root masuk daftar pantau. Bukan langsung ditolak,
            // karena banyak pengguna sah memakai HP root, tapi kombinasi root
            // dan ping mock adalah sinyal kuat.
            $table->boolean('is_rooted')->default(false);

            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
        });

        DB::statement("
            ALTER TABLE user_devices ADD CONSTRAINT user_devices_platform_check
            CHECK (platform IN ('android','ios'))
        ");

        // Pengiriman push mengambil token device yang masih aktif.
        DB::statement('
            CREATE INDEX user_devices_active_tokens ON user_devices (user_id)
            WHERE revoked_at IS NULL AND fcm_token IS NOT NULL
        ');
    }

    // -------------------------------------------------------------------------

    private function createUserAddresses(): void
    {
        Schema::create('user_addresses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('label', 60);
            $table->text('address');
            $table->string('detail', 255)->nullable();
            $table->string('note', 255)->nullable();

            // Presisi 7 desimal cukup untuk sekitar 1 cm, jauh lebih halus
            // dari akurasi GPS ponsel mana pun. DECIMAL, bukan float, supaya
            // koordinat yang disimpan sama persis dengan yang dikirim.
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);

            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 20)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Satu alamat utama per user, ditegakkan database. Partial unique index
        // lagi: banyak baris is_primary = false diizinkan, yang true hanya satu.
        DB::statement('
            CREATE UNIQUE INDEX user_addresses_one_primary ON user_addresses (user_id)
            WHERE is_primary = true
        ');

        DB::statement('CREATE INDEX user_addresses_user ON user_addresses (user_id, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    private function createOtpRequests(): void
    {
        Schema::create('otp_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('phone', 20);

            // Hash, bukan kode asli. Dump database yang bocor tidak boleh
            // memberi siapa pun kemampuan login sebagai orang lain.
            $table->string('code_hash', 255);

            $table->string('purpose', 20);
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement("
            ALTER TABLE otp_requests ADD CONSTRAINT otp_requests_purpose_check
            CHECK (purpose IN ('login','register','change_phone'))
        ");

        // Verifikasi mencari OTP terakhir yang belum dipakai untuk satu nomor.
        DB::statement('CREATE INDEX otp_requests_phone_created ON otp_requests (phone, created_at DESC)');

        DB::statement('
            CREATE INDEX otp_requests_pending ON otp_requests (phone, expires_at)
            WHERE consumed_at IS NULL
        ');

        // Deteksi penyalahgunaan per IP.
        DB::statement('CREATE INDEX otp_requests_ip_created ON otp_requests (ip_address, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    private function createSessions(): void
    {
        // Dipakai guard 'admin'. Session driver di .env memang redis, tapi
        // tabel ini tetap dibuat supaya bisa dipindah ke database tanpa
        // migration tambahan saat produksi butuh audit sesi.
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('otp_requests');
        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('users');
    }
};
