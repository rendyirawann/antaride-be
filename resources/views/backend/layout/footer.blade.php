<div class="footer py-4 d-flex flex-lg-column" id="kt_footer">
    <div class="container-xxl d-flex flex-column flex-md-row flex-stack">
        <div class="text-muted order-2 order-md-1">
            <span class="text-muted fw-semibold me-1">{{ now()->year }}</span>
            <span class="text-gray-800">{{ config('antaride.brand.name', 'Antaride') }}</span>
        </div>

        {{--
            Waktu server dan versi ditampilkan di setiap halaman.

            Bukan hiasan. Saat ada laporan "panel menampilkan angka lama", dua
            pertanyaan pertama selalu: versi mana yang sedang jalan, dan apakah jam
            servernya benar. Menaruhnya di footer menghilangkan satu langkah dari
            setiap penelusuran.

            Waktunya dalam zona BISNIS, bukan UTC. Panel ini dibaca staf ops di
            Medan, dan angka jam yang berbeda tujuh jam dari jam dinding mereka
            adalah sumber kekeliruan yang berulang.
        --}}
        <ul class="menu menu-gray-600 menu-hover-primary fw-semibold order-1">
            <li class="menu-item">
                <span class="menu-link px-2 text-muted">
                    {{ \App\Domain\Shared\Support\BusinessClock::now()->format('d M Y, H:i') }} WIB
                </span>
            </li>
            <li class="menu-item">
                <span class="menu-link px-2 text-muted">Laravel {{ app()->version() }}</span>
            </li>
        </ul>
    </div>
</div>
