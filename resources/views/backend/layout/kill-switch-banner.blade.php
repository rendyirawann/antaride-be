{{--
    Peringatan kalau ada kill switch yang sedang mematikan bagian sistem.

    ============================================================================
     KENAPA INI ADA DI SETIAP HALAMAN
    ============================================================================
     Kill switch dimatikan saat insiden, lalu insidennya selesai — dan tidak ada
     yang ingat menyalakannya lagi. Yang terjadi berikutnya: order tidak masuk
     selama berjam-jam, dan yang menyadarinya adalah orang yang bertanya kenapa
     pendapatan hari itu setengah.

     Banner yang hanya ada di halaman pengaturan tidak akan pernah dilihat,
     karena halaman itu justru yang tidak dibuka saat semuanya terlihat normal.
     Menaruhnya di SETIAP halaman panel berarti tidak ada cara mengabaikannya.
    ============================================================================
--}}
@php
    /**
     * Hanya switch yang MEMATIKAN yang diperiksa.
     *
     * Flag yang menyala adalah keadaan normal dan tidak perlu diberitahukan.
     * Daftar ini sengaja pendek: hanya yang dampaknya langsung terasa pengguna.
     */
    $switchPenting = [
        'orders.accepting_new' => 'Order baru sedang DISETOP. Tidak ada pesanan yang bisa masuk.',
        'payment.wallet_enabled' => 'Pembayaran dengan saldo sedang dimatikan.',
        'payment.cash_enabled' => 'Pembayaran tunai sedang dimatikan.',
        'withdrawal.enabled' => 'Penarikan saldo driver sedang dimatikan.',
        'driver.can_go_online' => 'Driver tidak bisa online.',
    ];

    $yangMati = [];

    foreach ($switchPenting as $kunci => $pesan) {
        // Default TRUE: flag yang tidak ada di database berarti belum pernah
        // dimatikan, dan itu keadaan normal. Menganggapnya mati akan membuat
        // banner muncul di database yang baru di-migrate tanpa di-seed.
        if (! \App\Domain\Support\Models\FeatureFlag::isEnabled($kunci, default: true)) {
            $yangMati[$kunci] = $pesan;
        }
    }
@endphp

@if ($yangMati !== [])
    <div class="alert alert-danger d-flex flex-column mb-6">
        <div class="d-flex align-items-center mb-2">
            <span class="fw-bolder fs-5">Ada bagian sistem yang sedang dimatikan</span>
        </div>

        <ul class="mb-3 ps-6">
            @foreach ($yangMati as $pesan)
                <li>{{ $pesan }}</li>
            @endforeach
        </ul>

        @can('feature_flags.manage')
            <div>
                <a href="{{ route('admin.settings.flags') }}" class="btn btn-sm btn-danger">
                    Buka kill switch
                </a>
            </div>
        @endcan
    </div>
@endif
