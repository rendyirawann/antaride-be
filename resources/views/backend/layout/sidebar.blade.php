{{--
    Sidebar off-canvas: pintasan yang paling sering dipakai.

    ============================================================================
     KENAPA SIDEBAR-NYA BUKAN NAVIGASI UTAMA
    ============================================================================
     Navigasi utama ada di menu horizontal di header. Sidebar ini berisi hal-hal
     yang sifatnya berbeda: angka yang perlu terlihat tanpa membuka halaman, dan
     tindakan darurat.

     Membuat sidebar sebagai salinan kedua dari menu utama adalah kesalahan yang
     paling sering terjadi pada panel Metronic: dua daftar yang harus sepakat,
     dan yang di sidebar akan tertinggal setiap kali ada halaman baru.
    ============================================================================
--}}
<div id="kt_app_sidebar">
    <div class="p-6">
        <div class="d-flex flex-stack mb-6">
            <span class="fs-4 fw-bold text-gray-800">Ringkasan</span>
            <button type="button" class="btn btn-icon btn-sm btn-active-light-primary" id="kt_sidebar_toggle_desktop">
                <i class="ki-duotone ki-cross fs-2"><span class="path1"></span><span class="path2"></span></i>
            </button>
        </div>

        {{--
            Angka-angka ini disuntikkan view composer, bukan diambil per halaman.

            Kalau setiap controller harus mengirimnya sendiri, akan ada halaman
            yang lupa — dan sidebar-nya menampilkan nol untuk angka yang
            sebenarnya tidak nol. Nol yang salah di panel ops lebih berbahaya
            daripada tidak ada angka sama sekali, karena dia terlihat seperti
            jawaban.
        --}}
        <div class="d-flex flex-column gap-4">

            <div class="d-flex flex-stack">
                <span class="text-muted fw-semibold fs-7">Order berjalan</span>
                <span class="fw-bold fs-5 text-gray-900">{{ $ringkasan['order_berjalan'] ?? 0 }}</span>
            </div>

            <div class="d-flex flex-stack">
                <span class="text-muted fw-semibold fs-7">Driver online</span>
                <span class="fw-bold fs-5 text-gray-900">{{ $ringkasan['driver_online'] ?? 0 }}</span>
            </div>

            {{--
                Order yang macet mencari driver ditandai MERAH, bukan hanya
                dihitung.

                Ini satu-satunya angka di panel yang berarti ada penumpang
                sedang menatap layar tanpa jawaban. Angka yang tampil sama
                seperti angka lain akan dibaca sebagai statistik biasa.
            --}}
            <div class="d-flex flex-stack">
                <span class="text-muted fw-semibold fs-7">Macet mencari driver</span>
                <span
                    class="fw-bold fs-5 {{ ($ringkasan['order_macet'] ?? 0) > 0 ? 'text-danger' : 'text-gray-900' }}">
                    {{ $ringkasan['order_macet'] ?? 0 }}
                </span>
            </div>

            <div class="separator"></div>

            @can('finance.approve_withdrawal')
                <div class="d-flex flex-stack">
                    <span class="text-muted fw-semibold fs-7">Penarikan menunggu</span>
                    <span
                        class="fw-bold fs-5 {{ ($jumlahPenarikanTertunda ?? 0) > 0 ? 'text-warning' : 'text-gray-900' }}">
                        {{ $jumlahPenarikanTertunda ?? 0 }}
                    </span>
                </div>
            @endcan

            @can('drivers.verify_document')
                <div class="d-flex flex-stack">
                    <span class="text-muted fw-semibold fs-7">Verifikasi menunggu</span>
                    <span
                        class="fw-bold fs-5 {{ ($jumlahVerifikasiTertunda ?? 0) > 0 ? 'text-warning' : 'text-gray-900' }}">
                        {{ $jumlahVerifikasiTertunda ?? 0 }}
                    </span>
                </div>
            @endcan
        </div>

        {{--
            Tombol darurat.

            Ditaruh di sidebar, bukan di menu, dan sengaja tidak nyaman
            dijangkau: dia harus bisa ditemukan dalam sepuluh detik saat ada
            insiden, tapi tidak boleh berada di jalur klik sehari-hari.
        --}}
        @can('feature_flags.manage')
            <div class="separator my-6"></div>

            <div class="d-flex flex-column gap-3">
                <span class="text-muted fw-semibold fs-8 text-uppercase">Darurat</span>

                @php
                    $terimaOrderBaru = \App\Domain\Support\Models\FeatureFlag::isEnabled('orders.accepting_new');
                @endphp

                @if ($terimaOrderBaru)
                    <form method="POST" action="{{ route('admin.settings.flags.toggle', 'orders.accepting_new') }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="enabled" value="0">

                        <button type="button" class="btn btn-sm btn-light-danger w-100"
                            data-konfirmasi-judul="Setop penerimaan order baru?"
                            data-konfirmasi="Order yang <b>sedang berjalan tetap diselesaikan</b>, tapi tidak ada order baru yang bisa dibuat di seluruh kota.<br><br>Driver yang sedang online akan berhenti mendapat penawaran."
                            data-konfirmasi-ya="Ya, setop order baru">
                            Setop order baru
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.settings.flags.toggle', 'orders.accepting_new') }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="enabled" value="1">

                        <button type="submit" class="btn btn-sm btn-light-success w-100">
                            Buka lagi order baru
                        </button>
                    </form>
                @endif

                <a href="{{ route('admin.settings.flags') }}" class="btn btn-sm btn-light w-100">
                    Semua kill switch
                </a>
            </div>
        @endcan
    </div>
</div>
