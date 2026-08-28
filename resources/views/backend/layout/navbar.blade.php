{{--
    Topbar: tema dan menu akun.
--}}
@php
    $admin = auth('admin')->user();
    $inisial = mb_strtoupper(mb_substr((string) ($admin->name ?? '?'), 0, 1));
@endphp

<div class="d-flex align-items-stretch flex-shrink-0">

    {{-- Notifikasi: pekerjaan yang belum selesai --}}
    @include('backend.layout.alerts')

    {{-- Tema --}}
    <div class="d-flex align-items-center ms-1 ms-lg-3">
        <a href="#" class="btn btn-icon btn-active-light-primary btn-custom w-30px h-30px w-md-40px h-md-40px"
            data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-attach="parent"
            data-kt-menu-placement="bottom-end">
            <i class="ki-duotone ki-night-day theme-light-show fs-1">
                <span class="path1"></span><span class="path2"></span><span class="path3"></span>
                <span class="path4"></span><span class="path5"></span><span class="path6"></span>
                <span class="path7"></span><span class="path8"></span><span class="path9"></span>
                <span class="path10"></span>
            </i>
            <i class="ki-duotone ki-moon theme-dark-show fs-1"><span class="path1"></span><span class="path2"></span></i>
        </a>

        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-title-gray-700 menu-icon-muted menu-active-bg menu-state-primary fw-semibold py-4 fs-base w-150px"
            data-kt-menu="true" data-kt-element="theme-mode-menu">
            <div class="menu-item px-3 my-0">
                <a href="#" class="menu-link px-3 py-2" data-kt-element="mode" data-kt-value="light">
                    <span class="menu-title">Terang</span>
                </a>
            </div>
            <div class="menu-item px-3 my-0">
                <a href="#" class="menu-link px-3 py-2" data-kt-element="mode" data-kt-value="dark">
                    <span class="menu-title">Gelap</span>
                </a>
            </div>
            <div class="menu-item px-3 my-0">
                <a href="#" class="menu-link px-3 py-2" data-kt-element="mode" data-kt-value="system">
                    <span class="menu-title">Sistem</span>
                </a>
            </div>
        </div>
    </div>

    {{-- Akun --}}
    <div class="d-flex align-items-center ms-1 ms-lg-3" id="kt_header_user_menu_toggle">
        <div class="btn btn-flex align-items-center bg-hover-light py-2 px-2 px-md-3"
            data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-attach="parent"
            data-kt-menu-placement="bottom-end">
            <div class="d-none d-md-flex flex-column align-items-end justify-content-center me-2 me-md-4">
                <span class="text-muted fs-8 fw-semibold lh-1 mb-1">
                    {{ $admin?->getRoleNames()->first() ?? 'staf' }}
                </span>
                <span class="text-gray-900 fs-7 fw-bold lh-1">{{ $admin->name ?? '' }}</span>
            </div>
            <div class="symbol symbol-30px symbol-md-40px">
                <div class="symbol-label fs-4 fw-bold bg-light-primary text-primary">{{ $inisial }}</div>
            </div>
        </div>

        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-color fw-semibold py-4 fs-6 w-275px"
            data-kt-menu="true">
            <div class="menu-item px-3">
                <div class="menu-content d-flex align-items-center px-3">
                    <div class="symbol symbol-50px me-5">
                        <div class="symbol-label fs-2 fw-bold bg-light-primary text-primary">{{ $inisial }}</div>
                    </div>
                    <div class="d-flex flex-column">
                        <div class="fw-bold fs-5">{{ $admin->name ?? '' }}</div>
                        <span class="fw-semibold text-muted fs-7">{{ $admin->email ?? '' }}</span>
                    </div>
                </div>
            </div>

            <div class="separator my-2"></div>

            {{--
                Status 2FA ditampilkan di menu akun, bukan hanya di halaman
                pengaturan.

                Alasannya: 2FA yang belum aktif adalah satu-satunya hal yang
                membuat akun admin bisa dibobol hanya dengan kata sandi, dan
                pengingat yang hanya muncul di halaman yang harus dicari tidak
                akan pernah dilihat.
            --}}
            <div class="menu-item px-5">
                @if ($admin?->two_factor_confirmed_at)
                    <span class="menu-link px-5 text-success">2FA aktif</span>
                @else
                    <a href="{{ route('admin.two-factor.setup') }}" class="menu-link px-5 text-danger fw-bold">
                        2FA belum aktif — aktifkan
                    </a>
                @endif
            </div>

            <div class="separator my-2"></div>

            <div class="menu-item px-5">
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="menu-link px-5 border-0 bg-transparent w-100 text-start">
                        Keluar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
