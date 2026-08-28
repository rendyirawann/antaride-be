<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tata kelola: audit, approval dua tahap, feature flag, export, setting.
 *
 * Ini bagian yang hampir selalu ditambahkan SETELAH kejadian, bukan sebelum.
 * Aturan yang tidak bisa dinegosiasikan: tidak ada satu orang pun yang bisa
 * mengajukan dan menyetujui hal yang sama. Pemisahan tugas ini yang
 * menyelamatkan platform dari fraud internal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createAuditLogs();
        $this->createApprovalThresholds();
        $this->createApprovalRequests();
        $this->createFeatureFlags();
        $this->createExportJobs();
        $this->createSettings();
        $this->linkApprovalForeignKeys();
    }

    // -------------------------------------------------------------------------

    /**
     * Jejak audit tindakan admin.
     *
     * Ditulis dari dua tempat: middleware LogAdminActivity untuk tingkat
     * request, dan trait model untuk perubahan data dengan nilai sebelum dan
     * sesudah. Ditulis sendiri, bukan memakai paket, karena blueprint butuh
     * pencatatan pembukaan data KYC dan penandaan impersonasi yang tidak pas
     * dengan skema paket mana pun.
     */
    private function createAuditLogs(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // NULL berarti tindakan sistem, bukan manusia.
            $table->foreignId('admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            $table->string('action', 120);

            $table->string('auditable_type', 40)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->unsignedSmallInteger('status_code')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Terisi kalau tindakan dilakukan selama sesi impersonasi. Ini yang
            // membedakan "pengguna melakukannya" dari "CS melakukannya atas nama
            // pengguna", dan tanpa kolom ini keduanya tidak bisa dipisahkan.
            $table->foreignId('impersonated_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            $table->timestamp('created_at')->nullable();
        });

        // Riwayat satu record: "apa saja yang pernah terjadi pada driver ini".
        DB::statement('CREATE INDEX audit_logs_auditable ON audit_logs (auditable_type, auditable_id, created_at DESC)');

        // Riwayat satu staf: "apa saja yang dilakukan akun ini".
        DB::statement('CREATE INDEX audit_logs_admin ON audit_logs (admin_id, created_at DESC)');

        DB::statement('CREATE INDEX audit_logs_action ON audit_logs (action, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_created ON audit_logs (created_at DESC)');

        // Pencarian di dalam nilai yang berubah, saat menyelidiki insiden.
        DB::statement('CREATE INDEX audit_logs_new_values_gin ON audit_logs USING gin (new_values)');

        // Semua tindakan yang dilakukan lewat impersonasi. Ini yang pertama
        // dilihat kalau ada dugaan penyalahgunaan akses CS.
        DB::statement('
            CREATE INDEX audit_logs_impersonated ON audit_logs (impersonated_by_admin_id, created_at DESC)
            WHERE impersonated_by_admin_id IS NOT NULL
        ');
    }

    // -------------------------------------------------------------------------

    private function createApprovalThresholds(): void
    {
        Schema::create('approval_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 30);

            $table->bigInteger('min_amount')->default(0);

            // NULL berarti tanpa batas atas.
            $table->bigInteger('max_amount')->nullable();

            $table->string('required_role', 40)->nullable();
            $table->unsignedTinyInteger('required_approvers')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE approval_thresholds ADD CONSTRAINT approval_thresholds_range_check
            CHECK (min_amount >= 0 AND (max_amount IS NULL OR max_amount > min_amount))
        ');

        /*
         * Rentang nominal untuk satu jenis tidak boleh bertumpang tindih.
         *
         * Kalau bertumpang tindih, penarikan Rp 3 juta bisa jatuh ke dua aturan
         * sekaligus, dan yang menentukan berapa penyetuju yang dibutuhkan jadi
         * urutan baris yang dikembalikan query. Itu berarti kadang butuh dua
         * penyetuju, kadang satu, untuk nominal yang sama.
         *
         * int8range dipakai karena kolomnya bigint, dan '[)' membuat batas atas
         * eksklusif sehingga 500rb-5jt dan 5jt-tanpa-batas bisa bersambungan
         * tanpa dianggap tumpang tindih.
         */
        DB::statement("
            ALTER TABLE approval_thresholds ADD CONSTRAINT approval_thresholds_no_overlap
            EXCLUDE USING gist (
                type WITH =,
                int8range(min_amount, max_amount, '[)') WITH &&
            ) WHERE (is_active = true)
        ");

        DB::statement('
            CREATE INDEX approval_thresholds_lookup ON approval_thresholds (type, min_amount)
            WHERE is_active = true
        ');
    }

    // -------------------------------------------------------------------------

    private function createApprovalRequests(): void
    {
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('type', 30);

            // Apa yang akan dieksekusi kalau disetujui.
            $table->jsonb('payload');

            // Ringkasan dampak untuk ditampilkan ke penyetuju. Dihitung saat
            // pengajuan, supaya penyetuju melihat angka yang sama dengan yang
            // dilihat pengaju, bukan hasil hitung ulang yang bisa berbeda.
            $table->jsonb('preview')->nullable();

            $table->bigInteger('amount')->nullable();

            // Wajib, tidak boleh kosong, minimal 20 karakter. Friksi di tempat
            // yang tepat adalah fitur.
            $table->text('reason');

            $table->foreignId('requested_by_admin_id')->constrained('admins')->restrictOnDelete();
            $table->timestamp('requested_at');

            $table->string('status', 12)->default('pending');

            $table->foreignId('reviewed_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->unsignedTinyInteger('required_approvers')->default(1);
            $table->unsignedTinyInteger('approvals_received')->default(0);

            $table->timestamp('executed_at')->nullable();
            $table->jsonb('execution_result')->nullable();

            $table->timestamp('expires_at');
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check
            CHECK (type IN ('withdrawal','balance_adjustment','pricing_change',
                            'promo_launch','driver_unban','bulk_refund',
                            'merchant_commission','surge_manual','feature_flag'))
        ");

        DB::statement("
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check
            CHECK (status IN ('pending','approved','rejected','expired','executed','failed'))
        ");

        // Alasan wajib, dan panjangnya bermakna. Tanpa batas panjang, yang
        // terisi adalah titik satu karakter.
        DB::statement('
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_reason_check
            CHECK (length(trim(reason)) >= 20)
        ');

        /*
         * Pengaju tidak boleh jadi penyetuju.
         *
         * Ini juga ditegakkan di Action lewat SelfApprovalException, dan
         * pemeriksaan di sana yang memberi pesan error yang bisa dibaca staf.
         * Constraint ini ada karena penegakan di kode bisa dilewati: perintah
         * artisan yang ditulis cepat, seeder, atau UPDATE langsung lewat psql.
         *
         * Aturan ini yang menyelamatkan platform dari fraud internal, dan
         * hampir selalu ditambahkan setelah kejadian.
         */
        DB::statement('
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_no_self_approval
            CHECK (
                reviewed_by_admin_id IS NULL
                OR reviewed_by_admin_id <> requested_by_admin_id
            )
        ');

        /*
         * Pengajuan yang sudah diputuskan wajib punya penyetuju dan cap waktu.
         *
         * 'executed' dan 'failed' HARUS ikut disebut. Versi pertama constraint
         * ini hanya menyebut ('approved','rejected'), dan karena status akhir
         * jalur sukses adalah 'executed', satu UPDATE langsung ke 'executed'
         * melewati pemeriksaan ini SEKALIGUS melewati no_self_approval — yaitu
         * dua kontrol yang justru paling penting pada tabel ini. Hasilnya:
         * penarikan tereksekusi tanpa satu pun penyetuju tercatat.
         */
        DB::statement("
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_reviewed_shape_check
            CHECK (
                status NOT IN ('approved','rejected','executed','failed')
                OR (reviewed_by_admin_id IS NOT NULL AND reviewed_at IS NOT NULL)
            )
        ");

        /*
         * Kuorum harus benar-benar terpenuhi sebelum dieksekusi.
         *
         * Inilah yang mengubah `required_approvers` dari angka hiasan menjadi
         * aturan. Tanpa dia, kolom itu hanya dibaca kode aplikasi, dan satu
         * jalur yang lupa membacanya cukup untuk mencairkan dana bernilai besar
         * dengan satu tanda tangan.
         */
        DB::statement("
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_quorum_check
            CHECK (
                status NOT IN ('approved','executed')
                OR approvals_received >= required_approvers
            )
        ");

        /*
         * Tidak ada batas atas untuk approvals_received, dan itu disengaja.
         *
         * Kuorum yang KURANG adalah masalah keamanan; kuorum yang LEBIH tidak.
         * Kalau penyetuju ketiga ikut menandatangani pengajuan yang butuh dua,
         * yang benar adalah mencatatnya, bukan menolaknya dengan error
         * constraint yang tidak bisa dibaca staf.
         */
        DB::statement('
            ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_approvers_sane
            CHECK (required_approvers >= 1)
        ');

        // Antrean kerja penyetuju. Ini halaman yang paling sering dibuka role
        // finance setelah antrean penarikan.
        DB::statement("
            CREATE INDEX approval_requests_queue ON approval_requests (type, requested_at)
            WHERE status = 'pending'
        ");

        // Pengajuan yang kadaluarsa, untuk job pembersih.
        DB::statement("
            CREATE INDEX approval_requests_expiring ON approval_requests (expires_at)
            WHERE status = 'pending'
        ");

        DB::statement('CREATE INDEX approval_requests_requester ON approval_requests (requested_by_admin_id, requested_at DESC)');
        DB::statement('CREATE INDEX approval_requests_payload_gin ON approval_requests USING gin (payload)');

        $this->createApprovalRequestApprovals();
    }

    // -------------------------------------------------------------------------

    /**
     * Siapa saja yang sudah menyetujui, satu baris per orang.
     *
     * ========================================================================
     *  KENAPA TABEL INI ADA
     * ========================================================================
     *  Sebelumnya `approval_requests` hanya punya satu kolom
     *  `reviewed_by_admin_id` ditambah penghitung `approvals_received`. Untuk
     *  pengajuan yang butuh dua penyetuju, itu berarti identitas penyetuju
     *  PERTAMA hilang: kolomnya ditimpa oleh penyetuju kedua, dan yang tersisa
     *  hanya angka 2.
     *
     *  Konsekuensinya persis pada satu-satunya hal yang membuat aturan dua
     *  penyetuju ada gunanya. Saat terjadi pencairan dana yang dipertanyakan,
     *  pertanyaan pertama auditor adalah "siapa saja yang menyetujui ini", dan
     *  jawabannya tidak ada di mana pun. Lebih buruk: penghitung bisa naik dua
     *  kali oleh orang YANG SAMA yang menekan tombol dua kali, dan kuorumnya
     *  terlihat terpenuhi.
     *
     *  UNIQUE (approval_request_id, admin_id) yang menutup celah kedua, dan
     *  trigger di bawah yang menutup celah pengaju menyetujui pengajuannya
     *  sendiri — CHECK tidak bisa dipakai karena butuh melihat tabel lain.
     * ========================================================================
     */
    private function createApprovalRequestApprovals(): void
    {
        Schema::create('approval_request_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();

            $table->string('decision', 10);
            $table->text('note')->nullable();
            $table->timestamp('decided_at');

            // Jejak forensik. Kalau seorang admin membantah pernah menyetujui,
            // ini satu-satunya bukti yang tersisa.
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            // Satu admin, satu suara.
            $table->unique(['approval_request_id', 'admin_id']);
        });

        DB::statement("
            ALTER TABLE approval_request_approvals ADD CONSTRAINT approval_request_approvals_decision_check
            CHECK (decision IN ('approved','rejected'))
        ");

        /*
         * Pengaju tidak boleh menyetujui pengajuannya sendiri, dan penghitung
         * di tabel induk selalu sama dengan jumlah baris di sini.
         *
         * Keduanya dijadikan satu trigger karena keduanya harus berlaku pada
         * setiap tulis, tanpa kecuali: seeder, perintah artisan yang ditulis
         * cepat, dan UPDATE langsung lewat psql sama-sama tidak boleh lolos.
         * Penegakan di Action tetap ada karena di sanalah pesan error yang
         * bisa dibaca staf dihasilkan; ini jaring terakhirnya.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_approval_request_approvals()
            RETURNS trigger AS $$
            DECLARE
                requester bigint;
                target_id bigint;
            BEGIN
                target_id := COALESCE(NEW.approval_request_id, OLD.approval_request_id);

                IF TG_OP <> 'DELETE' THEN
                    SELECT requested_by_admin_id INTO requester
                    FROM approval_requests WHERE id = NEW.approval_request_id;

                    IF requester = NEW.admin_id THEN
                        RAISE EXCEPTION
                            'Admin % adalah pengaju approval_request % dan tidak boleh menyetujuinya sendiri.',
                            NEW.admin_id, NEW.approval_request_id
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                UPDATE approval_requests
                SET approvals_received = (
                    SELECT COUNT(*) FROM approval_request_approvals
                    WHERE approval_request_id = target_id AND decision = 'approved'
                )
                WHERE id = target_id;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('
            CREATE TRIGGER approval_request_approvals_sync
            AFTER INSERT OR UPDATE OR DELETE ON approval_request_approvals
            FOR EACH ROW
            EXECUTE FUNCTION sync_approval_request_approvals();
        ');

        DB::statement('
            CREATE INDEX approval_request_approvals_admin
            ON approval_request_approvals (admin_id, decided_at DESC)
        ');
    }

    // -------------------------------------------------------------------------

    /**
     * Feature flag dan kill switch.
     *
     * Ini yang membuat panel admin punya nilai operasional nyata, bukan cuma
     * jadi CRUD viewer. Tim ops harus bisa menghentikan penerimaan order saat
     * ada banjir tanpa menunggu deploy.
     */
    private function createFeatureFlags(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('description', 255)->nullable();

            $table->boolean('is_enabled')->default(true);

            // NULL berarti berlaku untuk semua zona.
            $table->jsonb('zone_ids')->nullable();

            // Untuk rilis bertahap. 0 berarti mati total walaupun is_enabled
            // true, 100 berarti semua.
            $table->unsignedTinyInteger('rollout_percent')->default(100);

            // Flag yang dimatikan sementara akan hidup sendiri setelah waktu
            // ini. Ini mencegah kill switch yang dinyalakan saat insiden lalu
            // lupa dimatikan, yang gejalanya adalah order tidak masuk selama
            // dua hari tanpa ada yang tahu kenapa.
            $table->timestamp('auto_revert_at')->nullable();

            $table->foreignId('updated_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->text('last_change_reason')->nullable();

            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE feature_flags ADD CONSTRAINT feature_flags_rollout_check
            CHECK (rollout_percent BETWEEN 0 AND 100)
        ');

        DB::statement('CREATE INDEX feature_flags_zone_gin ON feature_flags USING gin (zone_ids)');

        DB::statement('
            CREATE INDEX feature_flags_auto_revert ON feature_flags (auto_revert_at)
            WHERE auto_revert_at IS NOT NULL
        ');
    }

    // -------------------------------------------------------------------------

    private function createExportJobs(): void
    {
        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();

            $table->string('type', 40);

            // Filter yang dipakai, dicatat lengkap. Kalau data driver bocor,
            // kamu perlu tahu siapa yang terakhir mengunduhnya dan seberapa
            // banyak.
            $table->jsonb('filters')->nullable();

            $table->string('status', 12)->default('queued');
            $table->unsignedBigInteger('row_count')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);

            $table->text('error_message')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedSmallInteger('download_count')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_status_check
            CHECK (status IN ('queued','processing','completed','failed','expired'))
        ");

        DB::statement('
            ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_progress_check
            CHECK (progress_percent BETWEEN 0 AND 100)
        ');

        DB::statement('CREATE INDEX export_jobs_admin ON export_jobs (admin_id, created_at DESC)');

        // File yang sudah lewat masa retensi, untuk job pembersih.
        DB::statement("
            CREATE INDEX export_jobs_expiring ON export_jobs (expires_at)
            WHERE status = 'completed'
        ");
    }

    // -------------------------------------------------------------------------

    private function createSettings(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('group_name', 40)->default('general');
            $table->string('label', 150)->nullable();
            $table->string('description', 500)->nullable();

            // Setting yang boleh dibaca aplikasi mobile tanpa autentikasi.
            // Defaultnya false: setting baru tidak bocor ke publik hanya karena
            // yang menambahkannya lupa memikirkannya.
            $table->boolean('is_public')->default(false);

            $table->foreignId('updated_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE settings ADD CONSTRAINT settings_type_check
            CHECK (type IN ('string','integer','boolean','json','text','money'))
        ");

        DB::statement('CREATE INDEX settings_group ON settings (group_name)');

        DB::statement('
            CREATE INDEX settings_public ON settings (key)
            WHERE is_public = true
        ');
    }

    // -------------------------------------------------------------------------

    /**
     * Foreign key ke approval_requests dipasang di sini, karena tabel-tabel
     * yang merujuknya (pricing_rules, wallet_transactions, withdrawals, promos)
     * dibuat sebelum tabel approval_requests ada.
     */
    private function linkApprovalForeignKeys(): void
    {
        $links = [
            'pricing_rules',
            'wallet_transactions',
            'withdrawals',
            'promos',
        ];

        foreach ($links as $table) {
            DB::statement("
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_approval_request_id_foreign
                FOREIGN KEY (approval_request_id)
                REFERENCES approval_requests (id) ON DELETE SET NULL
            ");
        }

        // Alasan penolakan order food menunjuk ke cancellation_reasons.
        DB::statement('
            ALTER TABLE order_food
            ADD CONSTRAINT order_food_rejection_reason_id_foreign
            FOREIGN KEY (rejection_reason_id)
            REFERENCES cancellation_reasons (id) ON DELETE SET NULL
        ');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        DB::statement('ALTER TABLE order_food DROP CONSTRAINT IF EXISTS order_food_rejection_reason_id_foreign');

        foreach (['pricing_rules', 'wallet_transactions', 'withdrawals', 'promos'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_approval_request_id_foreign");
        }

        Schema::dropIfExists('settings');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_thresholds');
        Schema::dropIfExists('audit_logs');
    }
};
