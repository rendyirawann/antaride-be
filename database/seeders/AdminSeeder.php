<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Akun admin awal.
 *
 * Di lingkungan non-produksi dibuatkan satu akun per role, supaya pemisahan
 * tugas bisa benar-benar dicoba: masuk sebagai ops, ajukan perubahan tarif,
 * lalu masuk sebagai superadmin untuk menyetujuinya. Tanpa akun terpisah,
 * alur maker-checker tidak bisa diuji sama sekali.
 *
 * Di produksi hanya superadmin yang dibuat, dengan password dari environment.
 * Sisanya dibuat manual lewat panel, supaya setiap akun punya pemilik yang
 * jelas dan tercatat siapa yang membuatnya.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = $this->createSuperAdmin();

        if (app()->isProduction()) {
            $this->command->warn('  Produksi: hanya superadmin dibuat. Buat akun lain lewat panel.');

            return;
        }

        $this->createRoleAccounts();

        $this->command->info('  Admin: '.Admin::count().' akun.');
        $this->command->newLine();
        $this->command->line('  Masuk dengan: '.$superAdmin->email);
        $this->command->line('  Password    : password');
        $this->command->line('  Akun lain   : ops@antaride.test, verifier@, finance@, cs@, cssup@, marketing@, auditor@');
        $this->command->newLine();
        $this->command->warn('  2FA wajib untuk super-admin dan finance. Halaman setup muncul saat pertama masuk.');
    }

    private function createSuperAdmin(): Admin
    {
        $email = env('SEED_SUPERADMIN_EMAIL', 'superadmin@antaride.test');

        // Di produksi password WAJIB dari environment. Tidak ada default,
        // karena default yang tidak diganti adalah cara paling umum panel
        // admin jebol di minggu pertama.
        $password = env('SEED_SUPERADMIN_PASSWORD');

        if (app()->isProduction() && blank($password)) {
            throw new \RuntimeException(
                'SEED_SUPERADMIN_PASSWORD wajib diisi di produksi.'
            );
        }

        $admin = Admin::firstOrCreate(
            ['email' => $email],
            [
                'uuid' => (string) Str::uuid7(),
                'name' => 'Super Admin',
                'password' => Hash::make($password ?? 'password'),
                'status' => 'active',
            ],
        );

        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function createRoleAccounts(): void
    {
        $accounts = [
            'ops@antaride.test' => ['Ops Manager', 'ops-manager'],
            'verifier@antaride.test' => ['Verifikator Dokumen', 'driver-verifier'],
            'finance@antaride.test' => ['Finance', 'finance'],
            'cs@antaride.test' => ['CS Agent', 'cs-agent'],
            'cssup@antaride.test' => ['CS Supervisor', 'cs-supervisor'],
            'marketing@antaride.test' => ['Marketing', 'marketing'],
            'auditor@antaride.test' => ['Auditor', 'auditor'],
        ];

        foreach ($accounts as $email => [$name, $role]) {
            $admin = Admin::firstOrCreate(
                ['email' => $email],
                [
                    'uuid' => (string) Str::uuid7(),
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'status' => 'active',
                ],
            );

            $admin->syncRoles([$role]);
        }
    }
}
