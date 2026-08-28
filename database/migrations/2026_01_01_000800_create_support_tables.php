<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dukungan: tiket, pesan tiket, dan SOS.
 *
 * SOS dipisah dari tiket dengan sengaja. Tiket adalah pekerjaan yang menunggu
 * dalam antrean; SOS adalah orang yang sedang dalam bahaya di dalam kendaraan
 * asing. Menaruh keduanya di satu antrean berarti panggilan darurat akan
 * mengantre di belakang keluhan ongkos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createTicketCategories();
        $this->createTickets();
        $this->createTicketMessages();
        $this->createSosEvents();
    }

    // -------------------------------------------------------------------------

    private function createTicketCategories(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('default_priority', 10)->default('normal');

            // Target waktu respons, dipakai untuk menandai tiket yang sudah
            // lewat batas di antrean CS.
            $table->unsignedSmallInteger('sla_response_minutes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE ticket_categories ADD CONSTRAINT ticket_categories_priority_check
            CHECK (default_priority IN ('low','normal','high','urgent'))
        ");
    }

    // -------------------------------------------------------------------------

    private function createTickets(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('ticket_number', 24)->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()
                ->constrained('ticket_categories')->nullOnDelete();

            $table->string('subject', 200);
            $table->string('status', 15)->default('open');
            $table->string('priority', 10)->default('normal');

            $table->foreignId('assigned_to_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            // Cap waktu respons pertama, dipakai mengukur SLA. Dipisah dari
            // updated_at karena updated_at berubah setiap kali apa pun disentuh.
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Nominal refund yang diberikan lewat tiket ini, kalau ada. Batas
            // wewenangnya berbeda antara CS agent dan CS supervisor.
            $table->bigInteger('refund_amount')->default(0);

            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE tickets ADD CONSTRAINT tickets_status_check
            CHECK (status IN ('open','in_progress','waiting_user','resolved','closed'))
        ");

        DB::statement("
            ALTER TABLE tickets ADD CONSTRAINT tickets_priority_check
            CHECK (priority IN ('low','normal','high','urgent'))
        ");

        DB::statement('
            ALTER TABLE tickets ADD CONSTRAINT tickets_refund_check
            CHECK (refund_amount >= 0)
        ');

        // Tiket yang sudah selesai wajib punya cap waktunya.
        DB::statement("
            ALTER TABLE tickets ADD CONSTRAINT tickets_resolved_shape_check
            CHECK (status NOT IN ('resolved','closed') OR resolved_at IS NOT NULL)
        ");

        /*
         * =====================================================================
         *  KENAPA ADA priority_level DI SAMPING priority
         * =====================================================================
         *  Antrean CS harus mendahulukan prioritas tinggi. Versi pertama index
         *  ini menulis `ORDER BY priority DESC` di atas kolom TEKS — dan itu
         *  mengurutkan secara alfabet:
         *
         *      normal > low > high > urgent
         *
         *  Artinya 'high' justru berada PALING BAWAH, di bawah 'low'. Tiket
         *  urgent akan mengendap di dasar antrean sementara tiket normal
         *  dikerjakan lebih dulu. Bug yang tidak akan pernah muncul sebagai
         *  error: antreannya terisi, terurut, dan terlihat berfungsi.
         *
         *  Kolom turunan ini yang memperbaikinya. GENERATED ALWAYS ... STORED
         *  berarti nilainya dihitung database dari `priority`, jadi keduanya
         *  TIDAK MUNGKIN berbeda — tidak ada jalur tulis yang bisa lupa
         *  memperbaruinya, termasuk lewat psql langsung.
         *
         *  Kenapa bukan enum PostgreSQL asli (yang juga mengurut benar):
         *  menambah prioritas baru pada enum menuntut ALTER TYPE dan nilai
         *  barunya tidak bisa langsung dipakai di transaksi yang sama. Kolom
         *  turunan cukup diubah ekspresinya, tanpa operasi tipe.
         *
         *  `priority` tetap teks supaya baris tabelnya bisa dibaca manusia saat
         *  ditelusuri lewat psql; `priority_level` murni untuk mengurutkan.
         * =====================================================================
         */
        DB::statement("
            ALTER TABLE tickets ADD COLUMN priority_level smallint
            GENERATED ALWAYS AS (
                CASE priority
                    WHEN 'urgent' THEN 4
                    WHEN 'high'   THEN 3
                    WHEN 'normal' THEN 2
                    WHEN 'low'    THEN 1
                    ELSE 0
                END
            ) STORED
        ");

        // Antrean kerja CS: tiket yang belum selesai, prioritas tinggi dulu.
        DB::statement("
            CREATE INDEX tickets_queue ON tickets (priority_level DESC, created_at)
            WHERE status IN ('open','in_progress','waiting_user')
        ");

        // Tiket milik satu agen.
        DB::statement("
            CREATE INDEX tickets_assigned ON tickets (assigned_to_admin_id, created_at DESC)
            WHERE status IN ('open','in_progress','waiting_user')
        ");

        // Tiket belum direspons, untuk pemantauan SLA.
        DB::statement('
            CREATE INDEX tickets_unresponded ON tickets (created_at)
            WHERE first_responded_at IS NULL
        ');

        DB::statement('CREATE INDEX tickets_user ON tickets (user_id, created_at DESC)');
        DB::statement('CREATE INDEX tickets_number_trgm ON tickets USING gin (ticket_number gin_trgm_ops)');
    }

    // -------------------------------------------------------------------------

    private function createTicketMessages(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            $table->string('sender_type', 10);
            $table->unsignedBigInteger('sender_id');

            $table->text('message');
            $table->jsonb('attachments')->nullable();

            // Catatan internal antar staf, tidak pernah dikirim ke pengguna.
            // Pemisahan ini penting: tanpa dia, staf akan menulis catatan
            // internal di kolom yang sama dan pengguna akan membacanya.
            $table->boolean('is_internal_note')->default(false);

            $table->boolean('is_template')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        DB::statement("
            ALTER TABLE ticket_messages ADD CONSTRAINT ticket_messages_sender_check
            CHECK (sender_type IN ('user','agent','system'))
        ");

        DB::statement('CREATE INDEX ticket_messages_ticket ON ticket_messages (ticket_id, created_at)');
    }

    // -------------------------------------------------------------------------

    /**
     * Tombol darurat dari app.
     *
     * Tabel ini kecil dan harus tetap kecil. Yang penting bukan strukturnya,
     * tapi bahwa ada alert suara di panel ops dan ada orang yang benar-benar
     * memegangnya jam 11 malam.
     */
    private function createSosEvents(): void
    {
        Schema::create('sos_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->string('triggered_by_type', 10);
            $table->unsignedBigInteger('triggered_by_id');

            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->text('note')->nullable();

            // Snapshot keadaan saat tombol ditekan: siapa drivernya, plat apa,
            // di mana. Diambil sekarang karena data itu bisa berubah, dan yang
            // dibutuhkan penyelidikan adalah keadaan pada saat itu.
            $table->jsonb('context_snapshot')->nullable();

            $table->string('status', 15)->default('open');
            $table->foreignId('handled_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE sos_events ADD CONSTRAINT sos_events_triggered_by_check
            CHECK (triggered_by_type IN ('user','driver'))
        ");

        DB::statement("
            ALTER TABLE sos_events ADD CONSTRAINT sos_events_status_check
            CHECK (status IN ('open','acknowledged','contacted','resolved','false_alarm'))
        ");

        // Ini query yang dijalankan dashboard SOS setiap beberapa detik. Dibuat
        // partial supaya biayanya tetap sama walaupun tabelnya tumbuh bertahun.
        DB::statement("
            CREATE INDEX sos_events_active ON sos_events (created_at)
            WHERE status IN ('open','acknowledged','contacted')
        ");

        DB::statement('CREATE INDEX sos_events_order ON sos_events (order_id)');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('sos_events');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
    }
};
