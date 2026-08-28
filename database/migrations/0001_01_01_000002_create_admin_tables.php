<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staf internal: ops, finance, CS, verifikator dokumen, superadmin.
 *
 * Tabel terpisah total dari `users`, dengan guard dan session sendiri. Ini
 * keputusan keamanan: satu akun ops yang bobol bisa mengubah tarif seluruh kota
 * atau menyetujui penarikan fiktif, jadi tidak boleh ada satu pun jalur dari
 * alur registrasi atau reset password customer yang bisa berujung ke sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createAdmins();
        $this->createAdminIpAllowlist();
        $this->createAdminLoginAttempts();
    }

    private function createAdmins(): void
    {
        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('password');
            $table->string('phone', 20)->nullable();

            $table->string('status', 20)->default('active');

            // --- Two Factor (TOTP) ---
            //
            // Wajib untuk finance dan superadmin tanpa pengecualian. Secret dan
            // recovery code dienkripsi di lapisan model (cast 'encrypted'),
            // jadi dump database yang bocor tidak membawa 2FA ikut bocor.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('ALTER TABLE admins ALTER COLUMN email TYPE citext');

        DB::statement("
            ALTER TABLE admins ADD CONSTRAINT admins_status_check
            CHECK (status IN ('active','suspended'))
        ");

        // Email unik di antara akun yang belum dihapus. Admin yang keluar
        // di-soft-delete, dan emailnya boleh dipakai lagi kalau dia kembali.
        DB::statement('
            CREATE UNIQUE INDEX admins_email_unique ON admins (email)
            WHERE deleted_at IS NULL
        ');
    }

    private function createAdminIpAllowlist(): void
    {
        Schema::create('admin_ip_allowlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->constrained()->cascadeOnDelete();

            // Mendukung IP tunggal maupun CIDR (mis. 103.10.20.0/24), supaya
            // satu baris cukup untuk seluruh jaringan kantor.
            $table->string('cidr', 45);
            $table->string('label', 100)->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['admin_id', 'cidr']);
        });

        DB::statement('
            CREATE INDEX admin_ip_allowlist_active ON admin_ip_allowlist (admin_id)
            WHERE is_active = true
        ');
    }

    /**
     * Riwayat percobaan login admin.
     *
     * Rate limiter Laravel sudah menahan brute force lewat cache, tapi cache
     * bisa dibersihkan dan tidak meninggalkan jejak. Tabel ini yang menjawab
     * pertanyaan "sejak kapan akun ini dicoba, dari IP mana" saat ada insiden,
     * dan yang memicu notifikasi login dari device baru.
     */
    private function createAdminLoginAttempts(): void
    {
        Schema::create('admin_login_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable()
                ->constrained()->nullOnDelete();

            // Disimpan terpisah dari admin_id, karena percobaan login dengan
            // email yang tidak terdaftar juga perlu tercatat.
            $table->string('email', 190);

            $table->boolean('successful');
            $table->string('failure_reason', 60)->nullable();

            $table->string('ip_address', 45);
            $table->string('user_agent', 255)->nullable();

            // Sidik jari device sederhana, dipakai untuk mendeteksi login dari
            // perangkat yang belum pernah dipakai akun ini.
            $table->string('device_fingerprint', 64)->nullable();
            $table->boolean('is_new_device')->default(false);

            $table->timestamp('created_at')->nullable();
        });

        DB::statement('CREATE INDEX admin_login_attempts_email ON admin_login_attempts (email, created_at DESC)');
        DB::statement('CREATE INDEX admin_login_attempts_ip ON admin_login_attempts (ip_address, created_at DESC)');

        DB::statement('
            CREATE INDEX admin_login_attempts_failures ON admin_login_attempts (email, created_at DESC)
            WHERE successful = false
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_attempts');
        Schema::dropIfExists('admin_ip_allowlist');
        Schema::dropIfExists('admins');
    }
};
