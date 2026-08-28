{{--
    Lonceng notifikasi backoffice.

    ============================================================================
     ISINYA DITURUNKAN DARI KEADAAN, BUKAN DIBACA DARI TABEL
    ============================================================================
     Yang ditampilkan adalah PEKERJAAN YANG BELUM SELESAI: approval menunggu,
     order macet, dokumen driver belum diverifikasi.

     Karena diturunkan, dia tidak bisa basi — approval yang sudah disetujui
     hilang dari hitungan pada refresh berikutnya, tanpa ada yang perlu menandai
     apa pun. Penjelasan lengkapnya di `BuildAdminAlerts`.

     Konsekuensinya: TIDAK ADA "tandai sudah dibaca". Angkanya turun saat
     pekerjaannya diselesaikan, bukan saat seseorang menutup loncengnya — dan
     untuk daftar pekerjaan, itu justru yang benar.
    ============================================================================

     Action-nya di-resolve di sini, bukan lewat View Composer. Alasannya: partial
     ini satu-satunya yang memakainya, dan composer global berarti setiap view di
     panel ikut membawa data yang hanya dipakai satu partial.

     Hasilnya di-cache 30 detik di dalam Action, jadi resolve per render murah.
--}}
@php
    $alerts = app(\App\Domain\Support\Actions\BuildAdminAlerts::class)->handle();
@endphp

<div class="d-flex align-items-center ms-1 ms-lg-3">
    <a href="#"
        class="btn btn-icon btn-active-light-primary btn-custom position-relative w-30px h-30px w-md-40px h-md-40px"
        data-kt-menu-trigger="click"
        data-kt-menu-attach="parent"
        data-kt-menu-placement="bottom-end"
        title="{{ $alerts['total'] > 0 ? $alerts['total'] . ' hal menunggu ditangani' : 'Tidak ada yang menunggu' }}">

        <i class="ki-duotone ki-notification-bing fs-1">
            <span class="path1"></span><span class="path2"></span><span class="path3"></span>
        </i>

        @if ($alerts['total'] > 0)
            {{--
                Lencana angka, bukan titik.

                Titik hanya memberitahu "ada sesuatu"; angka memberitahu SEBERAPA
                BANYAK — dan itu yang menentukan apakah staf membukanya sekarang
                atau nanti.

                Dibatasi tampil "99+": lencana bertiga digit melebar keluar dari
                ikonnya, dan bedanya antara 143 dan 267 tidak mengubah apa pun
                yang akan dilakukan orangnya.
            --}}
            <span class="position-absolute translate-middle top-0 start-100 badge badge-circle badge-danger fs-9 fw-bold">
                {{ $alerts['total'] > 99 ? '99+' : $alerts['total'] }}
            </span>
        @endif
    </a>

    <div class="menu menu-sub menu-sub-dropdown menu-column w-350px w-lg-375px" data-kt-menu="true">

        <div class="d-flex flex-column bgi-no-repeat rounded-top bg-light-primary">
            <h3 class="text-gray-900 fw-semibold px-9 mt-6 mb-6">
                Perlu ditangani
                @if ($alerts['total'] > 0)
                    <span class="fs-8 opacity-75 ps-3">{{ $alerts['total'] }} total</span>
                @endif
            </h3>
        </div>

        <div class="tab-content">
            <div class="scroll-y mh-325px my-5 px-8">
                @forelse ($alerts['items'] as $item)
                    <a href="{{ $item['url'] }}" class="d-flex flex-stack py-4 text-decoration-none">
                        <div class="d-flex align-items-center">
                            <div class="symbol symbol-35px me-4">
                                <span class="symbol-label bg-light-{{ $item['severity'] }}">
                                    <i class="ki-duotone {{ $item['icon'] }} fs-2 text-{{ $item['severity'] }}">
                                        <span class="path1"></span><span class="path2"></span>
                                    </i>
                                </span>
                            </div>

                            <div class="mb-0 me-2">
                                <span class="fs-6 text-gray-800 fw-bold">{{ $item['label'] }}</span>
                            </div>
                        </div>

                        <span class="badge badge-light-{{ $item['severity'] }} fs-8">
                            {{ $item['count'] }}
                        </span>
                    </a>

                    @if (! $loop->last)
                        <div class="separator separator-dashed"></div>
                    @endif
                @empty
                    {{--
                        Keadaan kosong menyatakan bahwa TIDAK ADA yang menunggu,
                        bukan sekadar "tidak ada notifikasi".

                        Bedanya penting: "tidak ada notifikasi" bisa berarti
                        loncengnya rusak. "Semua sudah ditangani" adalah jawaban.
                    --}}
                    <div class="py-10 text-center">
                        <i class="ki-duotone ki-check-circle fs-3x text-success mb-4">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                        <div class="text-gray-700 fw-semibold">Semua sudah ditangani</div>
                        <div class="text-muted fs-7 mt-1">
                            Tidak ada approval, order macet, atau dokumen yang menunggu.
                        </div>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="py-3 text-center border-top">
            <span class="text-muted fs-8">
                {{--
                    Menyebutkan bahwa angkanya bisa tertinggal sampai 30 detik.

                    Tanpa keterangan ini, staf yang baru menyetujui approval lalu
                    melihat angkanya belum berubah akan menekan tombol setujunya
                    lagi — dan approval dua tahap yang ditekan dua kali oleh orang
                    yang sama adalah tepat yang dijaga sistemnya.
                --}}
                Diperbarui setiap 30 detik
            </span>
        </div>
    </div>
</div>
