<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC panel admin (blueprint admin bagian 8).
 *
 * Format slug: modul.aksi. Semuanya pada guard 'admin'.
 *
 * Tiga pemisahan di matriks ini disengaja dan mudah sekali dilanggar tanpa
 * sadar. Kalau nanti ada yang menambah permission, pegang ketiga hal ini:
 *
 *   1. Ops bisa MENGAJUKAN perubahan tarif, tapi tidak bisa MENYETUJUINYA.
 *   2. Verifier bisa melihat KYC penuh, tapi tidak bisa menyentuh saldo.
 *   3. Finance bisa menyetujui penarikan, tapi tidak bisa mengubah tarif.
 *
 * Dan yang paling penting: **tidak ada satu role pun yang bisa membuat
 * penarikan lalu menyetujuinya sendiri.** Pemisahan tugas inilah yang
 * menyelamatkan platform dari fraud internal, dan hampir selalu dilewatkan di
 * tahap awal.
 *
 * Auditor punya nol permission tulis. Itu bukan kelalaian, itu definisinya.
 */
class PermissionSeeder extends Seeder
{
    private const GUARD = 'admin';

    /**
     * Seluruh permission, dikelompokkan per modul dengan keterangan singkat.
     *
     * @var array<string, array<string, string>>
     */
    private const PERMISSIONS = [
        'dashboard' => [
            'dashboard.view' => 'Lihat dashboard',
            'dashboard.finance_metrics' => 'Lihat metrik keuangan di dashboard',
        ],
        'orders' => [
            'orders.view' => 'Lihat daftar dan detail order',
            'orders.view_all_zones' => 'Lihat order dari semua zona',
            'orders.intervene' => 'Intervensi order berjalan',
            'orders.force_assign' => 'Paksa assign driver ke order',
            'orders.cancel' => 'Batalkan order',
            'orders.adjust_fare' => 'Ubah tarif order dengan alasan',
            'orders.view_chat' => 'Lihat log percakapan order',
            'orders.replay_route' => 'Putar ulang rute order di peta',
        ],
        'drivers' => [
            'drivers.view' => 'Lihat daftar dan profil driver',
            'drivers.verify_document' => 'Verifikasi dokumen driver',
            'drivers.suspend' => 'Tangguhkan driver',
            'drivers.ban' => 'Blokir driver permanen',
            'drivers.edit_profile' => 'Ubah profil driver',
            'drivers.view_earnings' => 'Lihat pendapatan driver',
        ],
        'kyc' => [
            'kyc.view_masked' => 'Lihat data KYC tersamarkan',
            'kyc.view_full' => 'Lihat data KYC penuh (NIK, rekening)',
        ],
        'merchants' => [
            'merchants.view' => 'Lihat daftar dan detail merchant',
            'merchants.approve' => 'Setujui pendaftaran merchant',
            'merchants.edit_menu' => 'Ubah menu merchant',
            'merchants.set_commission' => 'Ubah komisi merchant',
        ],
        'pricing' => [
            'pricing.view' => 'Lihat tarif dan zona',
            'pricing.propose' => 'Ajukan perubahan tarif',
            'pricing.approve' => 'Setujui perubahan tarif',
            'pricing.surge_manual' => 'Nyalakan surge manual',
            'pricing.manage_zones' => 'Kelola polygon zona',
        ],
        'finance' => [
            'finance.view' => 'Lihat data keuangan dan ledger',
            'finance.approve_withdrawal' => 'Setujui penarikan saldo',
            'finance.adjust_balance' => 'Sesuaikan saldo wallet',
            'finance.reconcile' => 'Rekonsiliasi gateway',
            'finance.export' => 'Export laporan keuangan',
        ],
        'promos' => [
            'promos.view' => 'Lihat promo',
            'promos.create' => 'Buat dan ubah promo',
            'promos.approve' => 'Setujui peluncuran promo',
        ],
        'tickets' => [
            'tickets.view' => 'Lihat tiket dukungan',
            'tickets.reply' => 'Balas tiket',
            'tickets.refund_limited' => 'Refund sampai batas tertentu',
            'tickets.refund_unlimited' => 'Refund tanpa batas',
        ],
        'sos' => [
            'sos.handle' => 'Tangani panggilan darurat',
        ],
        'users' => [
            'users.view' => 'Lihat data pengguna',
            'users.impersonate' => 'Tinjau akun pengguna (hanya-baca)',
            'users.suspend' => 'Tangguhkan pengguna',
        ],
        'system' => [
            'admin.manage' => 'Kelola akun admin dan role',
            'settings.manage' => 'Ubah pengaturan sistem',
            'audit.view' => 'Lihat audit log',
            'feature_flags.manage' => 'Kelola feature flag dan kill switch',
            'exports.view' => 'Lihat dan unduh hasil export',
        ],
    ];

    /**
     * Pemetaan role ke permission, mengikuti matriks blueprint bagian 8.
     *
     * @var array<string, array{label: string, permissions: array<int, string>}>
     */
    private const ROLES = [
        'super-admin' => [
            'label' => 'Super Admin',
            // Diberi seluruh permission secara terprogram di bawah, bukan
            // didaftar manual, supaya permission baru tidak pernah terlewat.
            'permissions' => ['*'],
        ],

        'ops-manager' => [
            'label' => 'Ops Manager',
            'permissions' => [
                'dashboard.view', 'dashboard.finance_metrics',
                'orders.view', 'orders.view_all_zones', 'orders.intervene',
                'orders.force_assign', 'orders.cancel', 'orders.adjust_fare',
                'orders.view_chat', 'orders.replay_route',
                'drivers.view', 'drivers.suspend', 'drivers.ban',
                'drivers.edit_profile', 'drivers.view_earnings',
                'kyc.view_masked',
                'merchants.view', 'merchants.approve', 'merchants.edit_menu',
                'merchants.set_commission',
                // Mengajukan tarif, TIDAK menyetujui. Ini pemisahan yang
                // disengaja: yang mengusulkan angka bukan yang mengesahkannya.
                'pricing.view', 'pricing.propose', 'pricing.surge_manual',
                'pricing.manage_zones',
                'promos.view',
                'tickets.view',
                'users.view',
                'feature_flags.manage',
                'exports.view',
            ],
        ],

        'driver-verifier' => [
            'label' => 'Verifikator Dokumen',
            'permissions' => [
                'dashboard.view',
                'drivers.view', 'drivers.verify_document',
                // Boleh melihat KYC penuh, karena itu memang pekerjaannya.
                // Tidak diberi satu pun permission keuangan.
                'kyc.view_masked', 'kyc.view_full',
            ],
        ],

        'finance' => [
            'label' => 'Finance',
            'permissions' => [
                'dashboard.view', 'dashboard.finance_metrics',
                'orders.view',
                'drivers.view', 'drivers.view_earnings',
                'kyc.view_masked', 'kyc.view_full',
                'finance.view', 'finance.approve_withdrawal',
                'finance.adjust_balance', 'finance.reconcile', 'finance.export',
                'tickets.refund_unlimited',
                // Tidak ada pricing.* sama sekali. Yang menyetujui uang keluar
                // tidak boleh juga menentukan harga.
                'exports.view',
            ],
        ],

        'cs-agent' => [
            'label' => 'CS Agent',
            'permissions' => [
                'dashboard.view',
                'orders.view', 'orders.view_chat',
                'drivers.view',
                // Tersamarkan saja. Agen CS tidak butuh NIK penuh untuk
                // menjawab keluhan ongkos.
                'kyc.view_masked',
                'merchants.view',
                'tickets.view', 'tickets.reply', 'tickets.refund_limited',
                'users.view',
            ],
        ],

        'cs-supervisor' => [
            'label' => 'CS Supervisor',
            'permissions' => [
                'dashboard.view',
                'orders.view', 'orders.view_chat', 'orders.intervene',
                'orders.cancel', 'orders.adjust_fare', 'orders.replay_route',
                'drivers.view',
                'kyc.view_masked',
                'merchants.view',
                'tickets.view', 'tickets.reply',
                'tickets.refund_limited', 'tickets.refund_unlimited',
                'sos.handle',
                'users.view', 'users.impersonate', 'users.suspend',
            ],
        ],

        'marketing' => [
            'label' => 'Marketing',
            'permissions' => [
                'dashboard.view',
                // Read-only untuk order. Marketing perlu tahu volume, tidak
                // perlu bisa menyentuhnya.
                'orders.view',
                'merchants.view',
                'promos.view', 'promos.create',
                'exports.view',
            ],
        ],

        'auditor' => [
            'label' => 'Auditor',
            'permissions' => [
                // Bisa melihat semuanya, mengubah nol hal. Tidak ada satu pun
                // permission tulis di daftar ini, dan itu memang definisinya.
                'dashboard.view', 'dashboard.finance_metrics',
                'orders.view', 'orders.view_all_zones', 'orders.view_chat',
                'orders.replay_route',
                'drivers.view', 'drivers.view_earnings',
                'kyc.view_masked',
                'merchants.view',
                'pricing.view',
                'finance.view',
                'promos.view',
                'tickets.view',
                'users.view',
                'audit.view',
                'exports.view',
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->createPermissions();
            $this->createRoles();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info(sprintf(
            '  RBAC: %d permission, %d role.',
            Permission::where('guard_name', self::GUARD)->count(),
            Role::where('guard_name', self::GUARD)->count(),
        ));

        $this->assertSeparationOfDuties();
    }

    private function createPermissions(): void
    {
        foreach (self::PERMISSIONS as $module => $permissions) {
            foreach ($permissions as $name => $description) {
                Permission::findOrCreate($name, self::GUARD);
            }
        }
    }

    private function createRoles(): void
    {
        $all = $this->allPermissionNames();

        foreach (self::ROLES as $name => $definition) {
            $role = Role::findOrCreate($name, self::GUARD);

            $permissions = $definition['permissions'] === ['*']
                ? $all
                : $definition['permissions'];

            $role->syncPermissions($permissions);
        }
    }

    /**
     * @return array<int, string>
     */
    private function allPermissionNames(): array
    {
        return array_merge(...array_map(
            static fn (array $group) => array_keys($group),
            array_values(self::PERMISSIONS),
        ));
    }

    /**
     * Pemeriksaan pemisahan tugas, dijalankan setiap kali seeder ini jalan.
     *
     * Ini bukan test, ini pengaman. Suatu hari akan ada yang menambahkan
     * permission ke role untuk "menyelesaikan masalah cepat", dan yang paling
     * mungkin ditambahkan adalah kombinasi yang justru dilarang. Kalau itu
     * terjadi, seeder ini berteriak, bukan diam.
     */
    private function assertSeparationOfDuties(): void
    {
        $forbidden = [
            // Tidak boleh ada role selain superadmin yang bisa mengajukan
            // sekaligus menyetujui hal yang sama.
            'ops-manager' => ['pricing.approve'],
            'finance' => ['pricing.propose', 'pricing.approve'],
            'driver-verifier' => [
                'finance.adjust_balance',
                'finance.approve_withdrawal',
                'drivers.ban',
            ],
            'cs-agent' => ['kyc.view_full', 'finance.adjust_balance'],
            'marketing' => ['orders.intervene', 'promos.approve'],
        ];

        $violations = [];

        foreach ($forbidden as $roleName => $mustNotHave) {
            $role = Role::findByName($roleName, self::GUARD);

            foreach ($mustNotHave as $permission) {
                if ($role->hasPermissionTo($permission, self::GUARD)) {
                    $violations[] = "{$roleName} tidak boleh punya {$permission}";
                }
            }
        }

        // Auditor wajib nol permission tulis.
        $auditor = Role::findByName('auditor', self::GUARD);
        $writeMarkers = ['intervene', 'approve', 'adjust', 'create', 'manage',
            'suspend', 'ban', 'cancel', 'reply', 'refund', 'impersonate',
            'verify', 'propose', 'edit', 'handle'];

        foreach ($auditor->permissions->pluck('name') as $permission) {
            foreach ($writeMarkers as $marker) {
                if (str_contains($permission, $marker)) {
                    $violations[] = "auditor tidak boleh punya {$permission} (permission tulis)";
                }
            }
        }

        if ($violations !== []) {
            $this->command->error('  PEMISAHAN TUGAS DILANGGAR:');

            foreach ($violations as $violation) {
                $this->command->error("    - {$violation}");
            }

            throw new \RuntimeException(
                'Seeder dibatalkan: matriks permission melanggar pemisahan tugas.'
            );
        }

        $this->command->info('  Pemisahan tugas: lolos.');
    }
}
