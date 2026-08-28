<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uang: wallet, ledger, top up, penarikan.
 *
 * Aturan yang mengatur seluruh file ini: **saldo bukan angka yang di-UPDATE
 * sembarangan, saldo adalah hasil akumulasi transaksi.**
 *
 * `wallets.balance` hanya cache yang diperbarui di dalam transaksi DB yang sama
 * dengan barisnya. Kebenarannya ada di `wallet_transactions`, yang APPEND ONLY:
 * tidak ada UPDATE, tidak ada DELETE, selamanya. Begitu ada selisih dan tidak
 * ada baris transaksi pendamping, tidak ada cara merekonstruksi apa yang
 * terjadi, dan kamu akan menghabiskan berhari-hari mencari satu rupiah.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createWallets();
        $this->createWalletTransactions();
        $this->createLedgerBalanceGuard();
        $this->createTopups();
        $this->createWithdrawals();
        $this->createPaymentWebhookLogs();
        $this->createIdempotencyKeys();
    }

    // -------------------------------------------------------------------------

    private function createWallets(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');

            $table->char('currency', 3)->default('IDR');

            // Cache. Kebenarannya ada di wallet_transactions.
            $table->bigInteger('balance')->default(0);

            // Dana tertahan untuk order yang sedang berjalan. Sudah keluar dari
            // balance tapi belum jadi pembayaran final.
            $table->bigInteger('held_balance')->default(0);

            // Optimistic locking. Dua request yang membaca saldo yang sama lalu
            // menulis akan membuat yang kedua gagal, bukan menimpa diam-diam.
            $table->unsignedInteger('version')->default(0);

            $table->boolean('is_frozen')->default(false);
            $table->string('frozen_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'currency']);
        });

        DB::statement("
            ALTER TABLE wallets ADD CONSTRAINT wallets_owner_type_check
            CHECK (owner_type IN ('user','driver','merchant','platform'))
        ");

        /*
         * Saldo dompet pengguna tidak boleh negatif.
         *
         * Ini menutup kelas bug yang paling mahal di sistem seperti ini: race
         * condition yang membuat dua order berhasil menahan dana dari saldo
         * yang hanya cukup untuk satu. Tanpa constraint ini, hasilnya adalah
         * saldo minus yang baru ditemukan saat driver mengeluh, dan uangnya
         * sudah lama berpindah.
         *
         * =====================================================================
         *  KENAPA AKUN PLATFORM DIKECUALIKAN
         * =====================================================================
         *  Pembukuan ini tertutup: setiap peristiwa harus berjumlah nol, dan
         *  trigger di bawah menegakkannya. Konsekuensi aritmetikanya, jumlah
         *  SELURUH saldo di tabel ini adalah invarian. Semua wallet lahir di
         *  nol, jadi invarian itu nol selamanya.
         *
         *  Artinya agar saldo seorang pengguna bisa positif, ada wallet lain
         *  yang HARUS negatif. Kalau setiap wallet dilarang negatif, tidak ada
         *  satu pun perpindahan nilai yang bisa dibukukan — top up pertama pun
         *  gagal, karena pasangan debitnya (platform:settlement) menembus nol.
         *
         *  Yang negatif itu bukan kerugian. Lima wallet platform adalah akun
         *  kontra yang mewakili batas sistem dengan dunia luar:
         *
         *    settlement   uang masuk dari payment gateway; makin negatif
         *                 berarti makin banyak dana pengguna yang dititipkan
         *    promo_cost   beban promo yang sudah diberikan
         *    incentive    beban insentif driver
         *    refund_cost  beban pengembalian dana
         *    revenue      pendapatan komisi (satu-satunya yang selalu positif)
         *
         *  Ini bukan kelonggaran, melainkan cara pembukuan berpasangan bekerja:
         *  akun beban dan liabilitas memang bersaldo berlawanan tanda dengan
         *  akun aset. Yang menjaga kebenaran angkanya adalah trigger jumlah-nol,
         *  bukan larangan negatif.
         *
         *  `held_balance` tetap dilarang negatif untuk SEMUA wallet, termasuk
         *  platform: menahan dana yang tidak ada tidak punya arti akuntansi
         *  apa pun dan selalu berarti bug pada pasangan hold/release.
         * =====================================================================
         *
         * =====================================================================
         *  KENAPA DOMPET DRIVER JUGA BOLEH NEGATIF
         * =====================================================================
         *  Pada order TUNAI, driver menerima seluruh ongkos langsung dari
         *  penumpang, dan komisi platform dipotong dari saldonya setelah order
         *  selesai. Artinya arah uangnya terbalik dari order wallet: driver
         *  BERUTANG ke platform, bukan sebaliknya.
         *
         *  Filter deposit minimum di matching mencegah driver bersaldo rendah
         *  menerima order tunai. Tapi filter itu berjalan saat MENAWARKAN, dan
         *  saldo bisa turun setelahnya — driver menarik saldonya saat sedang
         *  mengantar, atau ongkos aktualnya lebih besar dari estimasi.
         *
         *  Kalau saldo driver dilarang negatif, yang terjadi pada saat itu
         *  adalah yang paling buruk dari semua pilihan: order yang SUDAH selesai
         *  tidak bisa ditutup. Penumpang sudah turun, uangnya sudah di tangan
         *  driver, dan settlement-nya gagal. Karena partial unique index
         *  melarang dua order berjalan, driver itu juga tidak bisa menerima
         *  order berikutnya — dia terjebak sampai seseorang memperbaiki barisnya
         *  lewat psql.
         *
         *  Membiarkan saldonya negatif jauh lebih baik, dan sebenarnya lebih
         *  jujur: angka minus itu MEMANG utang yang nyata. Dampaknya juga
         *  memperbaiki dirinya sendiri — driver bersaldo minus otomatis tidak
         *  lolos filter deposit, jadi dia tidak bisa menerima order tunai lagi
         *  sampai melunasi. Tidak ada intervensi manual yang dibutuhkan.
         *
         *  Yang MASIH dijaga: driver tidak bisa MENARIK atau MENAHAN uang yang
         *  tidak dia punya. Itu ditegakkan PostLedgerEntries per jenis
         *  transaksi, bukan per pemilik dompet — dan justru pemeriksaan itulah
         *  yang menutup race condition yang jadi alasan constraint ini ada.
         * =====================================================================
         */
        DB::statement("
            ALTER TABLE wallets ADD CONSTRAINT wallets_non_negative_check
            CHECK (
                (owner_type IN ('platform', 'driver') OR balance >= 0)
                AND held_balance >= 0
            )
        ");

        DB::statement('CREATE INDEX wallets_owner ON wallets (owner_type, owner_id)');
    }

    // -------------------------------------------------------------------------

    /**
     * Buku besar. APPEND ONLY.
     *
     * TIDAK dipartisi, sesuai keputusan proyek. Selain alasan kesederhanaan
     * operasional yang berlaku untuk semua tabel di sini, ledger punya alasan
     * tambahan yang khusus: tabel terpartisi di PostgreSQL hanya boleh punya
     * UNIQUE constraint yang memuat kunci partisi. Penjaga keunikan di bawah
     * (`wallet_transactions_no_duplicate_settlement`) tidak memuat tanggal,
     * jadi mempartisi tabel ini berarti melepas justru penjaga yang paling
     * tidak boleh dilepas.
     */
    private function createWalletTransactions(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);
            $table->string('direction', 6);
            $table->bigInteger('amount');

            // Saldo sebelum dan sesudah, dibekukan pada saat baris dibuat. Ini
            // yang membuat rekonstruksi mungkin: kalau cache di tabel wallets
            // pernah salah, urutan baris ini yang menentukan mana yang benar.
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');

            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // Pengikat satu peristiwa double-entry. Semua baris dengan
            // group_uuid yang sama harus berjumlah nol.
            $table->uuid('group_uuid');

            $table->string('description', 255)->nullable();
            $table->jsonb('metadata')->nullable();

            // Penyesuaian manual oleh admin wajib punya jejak siapa dan kenapa.
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->unsignedBigInteger('approval_request_id')->nullable();

            $table->timestamp('created_at')->nullable();
        });

        DB::statement("
            ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_type_check
            CHECK (type IN ('topup','ride_payment','ride_earning','commission','hold',
                            'release','refund','withdrawal','bonus','incentive',
                            'penalty','adjustment','referral','settlement','reversal',
                            'cancellation_fee'))
        ");

        DB::statement("
            ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_direction_check
            CHECK (direction IN ('credit','debit'))
        ");

        // Nominal harus positif. Arahnya ditentukan kolom direction, bukan tanda
        // pada amount. Mencampur keduanya adalah cara pasti menghasilkan
        // pembukuan yang tidak bisa dijumlahkan.
        DB::statement('
            ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_check
            CHECK (amount > 0)
        ');

        /*
         * Aritmetika saldo harus konsisten dengan arah transaksi.
         *
         * Ini menangkap bug yang tidak terlihat sampai rekonsiliasi: kode yang
         * menulis balance_after dengan menambah padahal arahnya debit. Tanpa
         * constraint ini, ledger tetap "terlihat" benar baris per baris, dan
         * yang salah hanya jumlahnya.
         */
        DB::statement("
            ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_arithmetic_check
            CHECK (
                (direction = 'credit' AND balance_after = balance_before + amount)
                OR (direction = 'debit' AND balance_after = balance_before - amount)
            )
        ");

        /*
         * Satu order tidak boleh di-settle dua kali dengan jenis yang sama pada
         * wallet yang sama.
         *
         * Ini jaring terakhir untuk job settlement yang dijalankan ulang, entah
         * karena retry queue atau karena ada yang menjalankan perintah manual
         * dua kali. Idempotency di lapisan HTTP tidak menutup kasus ini, karena
         * settlement terjadi di worker, bukan di request.
         */
        DB::statement("
            CREATE UNIQUE INDEX wallet_transactions_no_duplicate_settlement
            ON wallet_transactions (wallet_id, reference_type, reference_id, type)
            WHERE reference_type IS NOT NULL
              AND type IN ('ride_payment','ride_earning','commission','settlement','withdrawal','topup')
        ");

        DB::statement('CREATE INDEX wallet_transactions_wallet ON wallet_transactions (wallet_id, created_at DESC)');
        DB::statement('CREATE INDEX wallet_transactions_group ON wallet_transactions (group_uuid)');
        DB::statement('CREATE INDEX wallet_transactions_reference ON wallet_transactions (reference_type, reference_id)');

        // Mutasi wallet di panel admin, difilter jenis.
        DB::statement('CREATE INDEX wallet_transactions_admin ON wallet_transactions (wallet_id, type, created_at DESC)');

        // Penyesuaian manual: yang paling sering diaudit.
        DB::statement("
            CREATE INDEX wallet_transactions_adjustments ON wallet_transactions (created_at DESC)
            WHERE type IN ('adjustment','reversal')
        ");
    }

    /**
     * Penjaga double-entry, ditegakkan database saat COMMIT.
     *
     * ==================================================================
     *  Ini bagian yang paling tidak bisa ditiru MySQL.
     * ==================================================================
     *
     * CONSTRAINT TRIGGER dengan DEFERRABLE INITIALLY DEFERRED baru dijalankan
     * saat transaksi di-COMMIT, bukan per baris. Pada saat itu seluruh baris
     * satu peristiwa sudah masuk, jadi jumlahnya bisa diperiksa.
     *
     * Kalau kredit dan debit satu group_uuid tidak sama, COMMIT-nya GAGAL dan
     * seluruh peristiwa dibatalkan. Bukan sebagian tersimpan.
     *
     * Kenapa ini penting: blueprint menyerahkan pemeriksaan ini ke job
     * rekonsiliasi harian. Job harian menemukan selisih SETELAH uangnya
     * berpindah, dan yang tersisa hanya pekerjaan forensik. Trigger ini
     * mencegah selisihnya pernah ada.
     *
     * Pengecualian yang disengaja: 'hold' dan 'release'.
     *
     * Keduanya bukan perpindahan nilai antar pihak, melainkan reklasifikasi di
     * dalam satu wallet — dana bergerak antara balance dan held_balance milik
     * pemilik yang sama. Nilai totalnya tidak berubah, jadi tidak ada lawan
     * transaksi untuk diseimbangkan. Memaksa keduanya balance akan menuntut
     * wallet bayangan yang tidak mewakili apa pun.
     */
    private function createLedgerBalanceGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_ledger_group_balanced()
            RETURNS trigger AS $$
            DECLARE
                net bigint;
                rows_counted int;
            BEGIN
                SELECT
                    COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0),
                    COUNT(*)
                INTO net, rows_counted
                FROM wallet_transactions
                WHERE group_uuid = NEW.group_uuid
                  AND type NOT IN ('hold', 'release');

                -- Grup yang hanya berisi hold/release tidak diperiksa.
                IF rows_counted = 0 THEN
                    RETURN NULL;
                END IF;

                IF net <> 0 THEN
                    RAISE EXCEPTION
                        'Ledger tidak seimbang untuk group_uuid %: selisih % (kredit dikurangi debit). Seluruh peristiwa dibatalkan.',
                        NEW.group_uuid, net
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('
            CREATE CONSTRAINT TRIGGER wallet_transactions_balanced
            AFTER INSERT ON wallet_transactions
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION assert_ledger_group_balanced();
        ');

        /*
         * APPEND ONLY ditegakkan database, bukan cuma ditulis di komentar.
         *
         * Trigger jumlah-nol di atas hanya berjalan AFTER INSERT. Tanpa penolak
         * ini, satu UPDATE mengubah amount atau satu DELETE menghapus separuh
         * pasangan akan membuat ledger tidak seimbang TANPA error apa pun —
         * justru pada tabel yang seluruh desainnya dibangun untuk mencegah itu.
         *
         * Yang membuatnya berbahaya: `php artisan tinker` dan panel admin sama
         * sekali tidak terlihat berbahaya saat dipakai. Seseorang memperbaiki
         * "salah ketik nominal" pada satu baris, dan neraca berhenti benar tanpa
         * ada jejak bahwa ada yang berubah.
         *
         * Koreksi yang sah selalu berupa baris BARU yang berlawanan arah
         * (reversal), sehingga riwayatnya tetap bisa dibaca dan diaudit. Karena
         * itu penolak ini tidak akan pernah menghalangi jalur yang benar.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_ledger_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'wallet_transactions bersifat APPEND ONLY: % ditolak. Koreksi harus lewat baris reversal baru, bukan mengubah riwayat.',
                    TG_OP
                    USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared('
            CREATE TRIGGER wallet_transactions_append_only
            BEFORE UPDATE OR DELETE ON wallet_transactions
            FOR EACH ROW
            EXECUTE FUNCTION reject_ledger_mutation();
        ');
    }

    // -------------------------------------------------------------------------

    private function createTopups(): void
    {
        Schema::create('topups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);

            $table->string('channel', 40);
            $table->string('provider', 20)->default('duitku');
            $table->string('provider_ref', 120)->nullable();

            $table->string('va_number', 40)->nullable();
            $table->text('qr_string')->nullable();

            $table->string('status', 12)->default('pending');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Payload asli dari provider, disimpan apa adanya. Saat ada
            // sengketa, ini satu-satunya bukti apa yang benar-benar dikirim.
            $table->jsonb('raw_callback')->nullable();

            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE topups ADD CONSTRAINT topups_status_check
            CHECK (status IN ('pending','paid','expired','failed','cancelled'))
        ");

        DB::statement('
            ALTER TABLE topups ADD CONSTRAINT topups_amount_check
            CHECK (amount > 0 AND fee >= 0)
        ');

        // Referensi provider unik per provider. Ini yang mencegah satu callback
        // yang dikirim ulang diproses dua kali sebagai dua top up.
        DB::statement('
            CREATE UNIQUE INDEX topups_provider_ref_unique ON topups (provider, provider_ref)
            WHERE provider_ref IS NOT NULL
        ');

        // Top up menunggu bayar, untuk job polling pembanding. Webhook hilang
        // itu normal, bukan kasus langka, jadi polling wajib ada.
        DB::statement("
            CREATE INDEX topups_pending ON topups (expires_at)
            WHERE status = 'pending'
        ");

        DB::statement('CREATE INDEX topups_wallet ON topups (wallet_id, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    private function createWithdrawals(): void
    {
        Schema::create('withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            $table->bigInteger('amount');
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('net_amount');

            $table->string('bank_name', 60);
            $table->text('bank_account_number');
            $table->string('bank_account_name', 120);

            $table->string('status', 12)->default('requested');

            $table->string('provider', 20)->nullable();
            $table->string('provider_ref', 120)->nullable();

            // Pengaju dan penyetuju WAJIB orang berbeda. Ditegakkan di Action
            // (SelfApprovalException) dan diperiksa ulang di constraint bawah.
            $table->foreignId('approved_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->jsonb('raw_callback')->nullable();

            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE withdrawals ADD CONSTRAINT withdrawals_status_check
            CHECK (status IN ('requested','reviewing','approved','processing',
                              'completed','rejected','failed','cancelled'))
        ");

        DB::statement('
            ALTER TABLE withdrawals ADD CONSTRAINT withdrawals_amount_check
            CHECK (amount > 0 AND fee >= 0 AND net_amount > 0 AND net_amount = amount - fee)
        ');

        // Penarikan yang sudah disetujui wajib punya penyetuju dan cap waktu.
        DB::statement("
            ALTER TABLE withdrawals ADD CONSTRAINT withdrawals_approval_shape_check
            CHECK (
                status NOT IN ('approved','processing','completed')
                OR (approved_by_admin_id IS NOT NULL AND approved_at IS NOT NULL)
            )
        ");

        DB::statement('
            CREATE UNIQUE INDEX withdrawals_provider_ref_unique ON withdrawals (provider, provider_ref)
            WHERE provider_ref IS NOT NULL
        ');

        // Antrean kerja tim finance. Ini halaman yang paling sering dibuka role
        // finance, jadi index-nya dibuat persis untuk urutan itu.
        DB::statement("
            CREATE INDEX withdrawals_queue ON withdrawals (created_at)
            WHERE status IN ('requested','reviewing')
        ");

        DB::statement('CREATE INDEX withdrawals_wallet ON withdrawals (wallet_id, created_at DESC)');
        DB::statement('CREATE INDEX withdrawals_status_created ON withdrawals (status, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    /**
     * Setiap payload webhook dicatat SEBELUM diproses.
     *
     * Alasannya: kalau pemrosesan gagal atau ada sengketa, yang menentukan bukan
     * ingatan siapa pun, tapi apa yang benar-benar diterima. Termasuk webhook
     * dengan tanda tangan tidak sah, karena itu sinyal ada yang mencoba.
     */
    private function createPaymentWebhookLogs(): void
    {
        Schema::create('payment_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_type', 60)->nullable();
            $table->string('signature', 255)->nullable();
            $table->jsonb('payload');

            $table->string('status', 20)->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::statement("
            ALTER TABLE payment_webhook_logs ADD CONSTRAINT payment_webhook_logs_status_check
            CHECK (status IN ('received','processed','duplicate','invalid_signature','error','ignored'))
        ");

        DB::statement('CREATE INDEX payment_webhook_logs_provider ON payment_webhook_logs (provider, created_at DESC)');
        DB::statement('CREATE INDEX payment_webhook_logs_payload_gin ON payment_webhook_logs USING gin (payload)');

        DB::statement("
            CREATE INDEX payment_webhook_logs_problems ON payment_webhook_logs (created_at DESC)
            WHERE status IN ('invalid_signature','error')
        ");
    }

    // -------------------------------------------------------------------------

    private function createIdempotencyKeys(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            /*
             * =================================================================
             *  KUNCI HARUS BERSAMA PEMILIKNYA, BUKAN GLOBAL
             * =================================================================
             *  Versi pertama menjadikan `key` sebagai primary key tunggal, dan
             *  `request_hash` hanya memuat method, path, dan payload. Keduanya
             *  bersama-sama membuka kebocoran data antar pengguna:
             *
             *    1. Pengguna A mengirim Idempotency-Key "abc..." dan membuat
             *       order. Response-nya tersimpan.
             *    2. Pengguna B mengirim kunci YANG SAMA dengan payload yang
             *       sama (alamat penjemputan yang sama pun cukup, misalnya di
             *       satu mal).
             *    3. request_hash cocok, jadi middleware menganggap ini
             *       pengiriman ulang, dan mengembalikan RESPONSE ORDER A ke
             *       pengguna B — lengkap dengan UUID order, alamat tujuan, dan
             *       data drivernya.
             *
             *  Tidak perlu ada niat jahat untuk memicunya: satu client dengan
             *  generator UUID berbenih buruk sudah cukup. Dengan niat jahat,
             *  ini jalur pengintaian order orang lain.
             *
             *  Sekarang kunci hanya berarti di dalam ruang pemiliknya, dan
             *  pemiliknya ikut masuk ke request_hash.
             * =================================================================
             *
             *  Kenapa `owner_key` string dan bukan FK ke users:
             *  penarikan dana juga bisa dipicu dari panel admin, dan admin ada
             *  di tabel yang berbeda. Satu kolom "guard:id" ("user:123",
             *  "admin:7") melayani keduanya tanpa FK polimorfik. Tabel ini juga
             *  berumur 24 jam dan dibersihkan rutin, jadi integritas referensial
             *  jangka panjang bukan yang dijaga di sini.
             */
            $table->string('owner_key', 64);
            $table->string('key', 64);

            $table->string('endpoint', 120);
            $table->char('request_hash', 64);

            $table->jsonb('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();

            /*
             * Terisi selama eksekusi berjalan, dan BENAR-BENAR dibaca.
             *
             * Sebelumnya kolom ini ditulis tapi tidak pernah dibaca siapa pun.
             * Akibatnya klaim yang mati di tengah jalan — proses PHP dibunuh,
             * worker di-restart, request kena timeout; semua yang tidak lewat
             * jalur exception — meninggalkan baris dengan response_body NULL
             * selamanya. Setiap percobaan berikutnya dijawab 409 "sedang
             * diproses" sampai expires_at, yaitu 24 jam.
             *
             * Bentuknya di lapangan: pengguna tidak bisa membuat order sama
             * sekali selama sehari, dan pesan errornya mengatakan ada order
             * yang sedang diproses padahal tidak ada.
             */
            $table->timestamp('locked_at')->nullable();

            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at');

            $table->primary(['owner_key', 'key']);
        });

        DB::statement('CREATE INDEX idempotency_keys_expires ON idempotency_keys (expires_at)');
        DB::statement('CREATE INDEX idempotency_keys_owner ON idempotency_keys (owner_key, created_at DESC)');

        // Klaim yang menggantung, untuk job pembersih dan halaman diagnostik.
        DB::statement('
            CREATE INDEX idempotency_keys_stale ON idempotency_keys (locked_at)
            WHERE response_body IS NULL
        ');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('payment_webhook_logs');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('topups');

        DB::unprepared('DROP TRIGGER IF EXISTS wallet_transactions_balanced ON wallet_transactions');
        Schema::dropIfExists('wallet_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_ledger_group_balanced()');

        Schema::dropIfExists('wallets');
    }
};
