{{--
    Menu horizontal panel admin.

    ============================================================================
     MENU DIBANGUN DARI PERMISSION, DAN ITU BUKAN PENGGANTI OTORISASI
    ============================================================================
     Setiap butir dibungkus @can. Yang dilakukannya hanya menyembunyikan tautan
     yang tidak bisa dipakai — supaya staf CS tidak melihat sepuluh menu yang
     semuanya menolaknya.

     Yang MENEGAKKAN otorisasi adalah `can:` di routes/admin.php. Menyembunyikan
     tombol tidak melindungi apa pun: URL-nya tetap bisa diketik langsung, dan
     halaman panel admin tersimpan di riwayat browser siapa pun yang pernah
     memakai komputer itu.

     Dua lapis, dan yang di sini adalah yang kosmetik.
    ============================================================================
--}}
@php
    /**
     * Menandai menu yang sedang terbuka.
     *
     * Dicocokkan dengan pola nama route, bukan dengan URL. Nama route stabil;
     * URL berubah begitu prefix admin dipindah ke subdomain, dan penandanya akan
     * diam-diam berhenti bekerja tanpa ada yang menyadarinya.
     */
    $aktif = fn (string $pola): string => request()->routeIs($pola) ? 'here show' : '';
    $aktifTautan = fn (string $pola): string => request()->routeIs($pola) ? 'active' : '';
@endphp

<div class="header-menu align-items-stretch" data-kt-drawer="true" data-kt-drawer-name="header-menu"
    data-kt-drawer-activate="{default: true, lg: false}" data-kt-drawer-overlay="true" data-kt-drawer-width="250px"
    data-kt-drawer-direction="end" data-kt-drawer-toggle="#kt_header_menu_toggle"
    data-kt-swapper="true" data-kt-swapper-mode="prepend"
    data-kt-swapper-parent="{default: '#kt_body', lg: '#kt_header_nav'}">

    <div class="menu menu-lg-rounded menu-column menu-lg-row menu-state-bg menu-title-gray-700 menu-state-title-primary menu-state-icon-primary menu-state-bullet-primary menu-arrow-gray-400 fw-semibold my-5 my-lg-0 align-items-stretch px-2 px-lg-0">

        {{-- Dashboard --}}
        @can('dashboard.view')
            <div class="menu-item me-lg-1">
                <a class="menu-link py-3 {{ $aktifTautan('admin.dashboard') }}"
                    href="{{ route('admin.dashboard') }}">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-element-11 fs-2"><span class="path1"></span><span
                                class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                    </span>
                    <span class="menu-title">Dashboard</span>
                </a>
            </div>
        @endcan

        {{-- Operasional: order dan live map --}}
        @canany(['orders.view', 'sos.handle'])
            <div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion me-lg-1 {{ $aktif('admin.orders.*') || $aktif('admin.livemap.*') ? 'here show' : '' }}">
                <span class="menu-link py-3">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-scooter fs-2"><span class="path1"></span><span
                                class="path2"></span></i>
                    </span>
                    <span class="menu-title">Operasional</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-200px">
                    @can('orders.view')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.orders.index') }}"
                                href="{{ route('admin.orders.index') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Daftar Order</span>
                            </a>
                        </div>
                    @endcan

                    @can('orders.view')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.livemap.*') }}"
                                href="{{ route('admin.livemap.index') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Live Map</span>
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
        @endcanany

        {{-- Driver --}}
        @can('drivers.view')
            <div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion me-lg-1 {{ $aktif('admin.drivers.*') }}">
                <span class="menu-link py-3">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-profile-user fs-2"><span class="path1"></span><span
                                class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                    </span>
                    <span class="menu-title">Driver</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-225px">
                    <div class="menu-item">
                        <a class="menu-link py-3 {{ $aktifTautan('admin.drivers.index') }}"
                            href="{{ route('admin.drivers.index') }}">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Semua Driver</span>
                        </a>
                    </div>

                    @can('drivers.verify_document')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.drivers.verification') }}"
                                href="{{ route('admin.drivers.verification') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Antrean Verifikasi</span>

                                {{--
                                    Jumlah antrean ditampilkan di menu.

                                    Ini satu-satunya angka yang muncul di menu, dan
                                    alasannya operasional: antrean verifikasi yang
                                    menumpuk berarti driver baru tidak bisa mulai
                                    bekerja, dan itu langsung memotong pasokan.
                                    Angka yang hanya terlihat setelah membuka
                                    halamannya akan terlambat dilihat.
                                --}}
                                @if (($jumlahVerifikasiTertunda ?? 0) > 0)
                                    <span class="badge badge-light-warning ms-2">{{ $jumlahVerifikasiTertunda }}</span>
                                @endif
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
        @endcan

        {{-- Merchant --}}
        @can('merchants.view')
            <div class="menu-item me-lg-1">
                <a class="menu-link py-3 {{ $aktifTautan('admin.merchants.*') }}"
                    href="{{ route('admin.merchants.index') }}">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-shop fs-2"><span class="path1"></span><span class="path2"></span><span
                                class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                    </span>
                    <span class="menu-title">Merchant</span>
                </a>
            </div>
        @endcan

        {{-- Tarif --}}
        @can('pricing.view')
            <div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion me-lg-1 {{ $aktif('admin.pricing.*') }}">
                <span class="menu-link py-3">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-price-tag fs-2"><span class="path1"></span><span
                                class="path2"></span><span class="path3"></span></i>
                    </span>
                    <span class="menu-title">Tarif</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-225px">
                    <div class="menu-item">
                        <a class="menu-link py-3 {{ $aktifTautan('admin.pricing.index') }}"
                            href="{{ route('admin.pricing.index') }}">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Aturan Tarif</span>
                        </a>
                    </div>

                    {{--
                        Simulator tarif ada DI SAMPING editornya, bukan di halaman
                        terpisah yang harus dicari.

                        Alasannya: tarif yang diubah tanpa disimulasikan lebih dulu
                        adalah cara paling langsung mengubah ongkos seluruh kota
                        secara tidak sengaja. Menaruh simulatornya satu klik dari
                        editornya membuat memeriksa lebih mudah daripada tidak
                        memeriksa.
                    --}}
                    <div class="menu-item">
                        <a class="menu-link py-3 {{ $aktifTautan('admin.pricing.simulator') }}"
                            href="{{ route('admin.pricing.simulator') }}">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Simulator Tarif</span>
                        </a>
                    </div>

                    @can('pricing.manage_zones')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.pricing.zones') }}"
                                href="{{ route('admin.pricing.zones') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Zona</span>
                            </a>
                        </div>
                    @endcan

                    @can('pricing.surge_manual')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.pricing.surge') }}"
                                href="{{ route('admin.pricing.surge') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Surge</span>
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
        @endcan

        {{-- Keuangan --}}
        @can('finance.view')
            <div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion me-lg-1 {{ $aktif('admin.finance.*') }}">
                <span class="menu-link py-3">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-dollar fs-2"><span class="path1"></span><span
                                class="path2"></span><span class="path3"></span></i>
                    </span>
                    <span class="menu-title">Keuangan</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-225px">
                    @can('finance.approve_withdrawal')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.finance.withdrawals') }}"
                                href="{{ route('admin.finance.withdrawals') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Penarikan</span>

                                @if (($jumlahPenarikanTertunda ?? 0) > 0)
                                    <span class="badge badge-light-danger ms-2">{{ $jumlahPenarikanTertunda }}</span>
                                @endif
                            </a>
                        </div>
                    @endcan

                    <div class="menu-item">
                        <a class="menu-link py-3 {{ $aktifTautan('admin.finance.ledger') }}"
                            href="{{ route('admin.finance.ledger') }}">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Buku Besar</span>
                        </a>
                    </div>

                    @can('finance.reconcile')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.finance.reconciliation') }}"
                                href="{{ route('admin.finance.reconciliation') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Rekonsiliasi</span>
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
        @endcan

        {{-- Sistem --}}
        @canany(['feature_flags.manage', 'audit.view', 'admin.manage', 'settings.manage'])
            <div data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion me-lg-1 {{ $aktif('admin.settings.*') || $aktif('admin.audit.*') || $aktif('admin.staff.*') ? 'here show' : '' }}">
                <span class="menu-link py-3">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-setting-2 fs-2"><span class="path1"></span><span
                                class="path2"></span></i>
                    </span>
                    <span class="menu-title">Sistem</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-250px">
                    @can('feature_flags.manage')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.settings.flags') }}"
                                href="{{ route('admin.settings.flags') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Kill Switch &amp; Feature Flag</span>
                            </a>
                        </div>
                    @endcan

                    @can('audit.view')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.audit.*') }}"
                                href="{{ route('admin.audit.index') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Audit Log</span>
                            </a>
                        </div>
                    @endcan

                    @can('admin.manage')
                        <div class="menu-item">
                            <a class="menu-link py-3 {{ $aktifTautan('admin.staff.*') }}"
                                href="{{ route('admin.staff.index') }}">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">Staf &amp; Role</span>
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
        @endcanany
    </div>
</div>
