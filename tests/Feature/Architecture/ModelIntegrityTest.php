<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Menjaga kesesuaian antara model, database, dan referensi antar class.
 *
 * ============================================================================
 *  KENAPA TEST INI ADA
 * ============================================================================
 *  Tiga kelas bug di bawah ini semuanya SENYAP. Tidak ada satu pun yang
 *  menggagalkan boot, menggagalkan `route:list`, atau muncul di Pint:
 *
 *   1. Relasi yang menunjuk class yang tidak ada. `::class` tidak
 *      meng-autoload, dan relasi Eloquent bersifat lazy, jadi kodenya berjalan
 *      normal sampai ada yang menyentuh relasi itu, lalu fatal di produksi.
 *
 *   2. Model yang tabelnya tidak ada, biasanya karena `$table` salah tulis atau
 *      konvensi jamak Laravel menghasilkan nama yang tidak dibuat migration.
 *
 *   3. Kolom di `$fillable` atau `casts()` yang tidak ada di tabel. Yang di
 *      fillable diam-diam diabaikan; yang di casts baru gagal saat diakses.
 *
 *  Ketiganya ditemukan di proyek ini secara nyata: sebelas model dirujuk tapi
 *  belum dibuat, dan tidak ada satu pun test yang gagal karenanya.
 * ============================================================================
 */
class ModelIntegrityTest extends TestCase
{
    /**
     * Kolom yang dikelola Eloquent sendiri dan tidak perlu ada di fillable.
     *
     * @var array<int, string>
     */
    private const MANAGED_COLUMNS = ['id', 'created_at', 'updated_at', 'deleted_at', 'uuid'];

    /**
     * Setiap referensi class di app/ harus bisa di-resolve.
     */
    public function test_tidak_ada_referensi_class_yang_menggantung(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->phpFiles() as $path => $source) {
            $namespace = $this->namespaceOf($source);
            $aliases = $this->aliasesOf($source);

            foreach ($this->classReferencesIn($source) as $ref) {
                if (in_array(strtolower($ref), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $checked++;

                if ($this->resolves($ref, $namespace, $aliases)) {
                    continue;
                }

                $missing[] = "{$ref}  (di {$path})";
            }
        }

        $this->assertGreaterThan(50, $checked, 'Pemindai tidak menemukan referensi; polanya mungkin rusak.');

        $this->assertSame(
            [],
            $missing,
            count($missing)." referensi class tidak dapat di-resolve:\n  "
            .implode("\n  ", $missing)
            ."\n\nIni tidak menggagalkan boot, tapi akan fatal saat relasinya disentuh.",
        );
    }

    /**
     * Setiap model harus punya tabel yang benar-benar ada.
     */
    public function test_setiap_model_punya_tabel_yang_ada(): void
    {
        $missing = [];

        foreach ($this->models() as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                $missing[] = "{$class} menunjuk tabel \"{$table}\" yang tidak ada";
            }
        }

        $this->assertSame([], $missing, implode("\n  ", $missing));
    }

    /**
     * Setiap kolom di $fillable harus ada di tabelnya.
     *
     * Kolom fillable yang tidak ada diam-diam diabaikan Eloquent, jadi
     * `create()` akan berhasil tapi nilainya tidak tersimpan. Gejalanya: data
     * yang hilang tanpa error.
     */
    public function test_kolom_fillable_ada_di_tabel(): void
    {
        $problems = [];

        foreach ($this->models() as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);

            foreach ($model->getFillable() as $column) {
                if (! in_array($column, $columns, true)) {
                    $problems[] = "{$class}: \"{$column}\" tidak ada di tabel {$table}";
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            count($problems)." kolom fillable tidak ada di tabelnya:\n  "
            .implode("\n  ", $problems),
        );
    }

    /**
     * Setiap kolom di casts() harus ada di tabelnya.
     */
    public function test_kolom_cast_ada_di_tabel(): void
    {
        $problems = [];

        foreach ($this->models() as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);

            foreach (array_keys($model->getCasts()) as $column) {
                if (in_array($column, self::MANAGED_COLUMNS, true)) {
                    continue;
                }

                if (! in_array($column, $columns, true)) {
                    $problems[] = "{$class}: cast \"{$column}\" tidak ada di tabel {$table}";
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            count($problems)." cast menunjuk kolom yang tidak ada:\n  "
            .implode("\n  ", $problems),
        );
    }

    /**
     * Setiap relasi harus bisa dibangun, dan foreign key-nya harus ada.
     *
     * Ini yang menangkap relasi dengan foreign key salah tulis. Tanpa test ini,
     * `$order->driver` akan mengembalikan null selamanya tanpa satu pun error,
     * dan yang terlihat di panel admin adalah kolom driver yang selalu kosong.
     */
    public function test_setiap_relasi_dapat_dibangun_dan_foreign_key_nya_ada(): void
    {
        $problems = [];

        foreach ($this->models() as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $ownColumns = Schema::getColumnListing($table);

            foreach ($this->relationMethods($class) as $method) {
                try {
                    $relation = $model->{$method}();
                } catch (\Throwable $e) {
                    $problems[] = "{$class}::{$method}() melempar: ".$e->getMessage();

                    continue;
                }

                if (! $relation instanceof Relation) {
                    continue;
                }

                $problem = $this->checkRelationKeys($class, $method, $relation, $ownColumns);

                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            count($problems)." relasi bermasalah:\n  ".implode("\n  ", $problems),
        );
    }

    // -------------------------------------------------------------------------
    // Pembantu
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, string>  $ownColumns
     */
    private function checkRelationKeys(
        string $class,
        string $method,
        Relation $relation,
        array $ownColumns,
    ): ?string {
        $related = $relation->getRelated();
        $relatedTable = $related->getTable();

        if (! Schema::hasTable($relatedTable)) {
            return "{$class}::{$method}() menunjuk tabel \"{$relatedTable}\" yang tidak ada";
        }

        $relatedColumns = Schema::getColumnListing($relatedTable);

        // BelongsTo: foreign key ada di tabel SENDIRI.
        if ($relation instanceof BelongsTo) {
            $fk = $relation->getForeignKeyName();

            if (! in_array($fk, $ownColumns, true)) {
                return "{$class}::{$method}() memakai foreign key \"{$fk}\" yang tidak ada di tabelnya sendiri";
            }

            return null;
        }

        // HasOne / HasMany: foreign key ada di tabel LAWAN.
        if ($relation instanceof HasOneOrMany) {
            $fk = $relation->getForeignKeyName();

            if (! in_array($fk, $relatedColumns, true)) {
                return "{$class}::{$method}() memakai foreign key \"{$fk}\" yang tidak ada di tabel {$relatedTable}";
            }

            return null;
        }

        return null;
    }

    /**
     * Method yang tipe kembaliannya turunan Relation.
     *
     * Dideteksi dari return type, bukan dari nama. Mendeteksi dari nama akan
     * salah menganggap `orders()` sebagai relasi padahal bisa jadi query scope.
     *
     * @return array<int, string>
     */
    private function relationMethods(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if ($method->class !== $class) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType) {
                continue;
            }

            $typeName = $returnType->getName();

            if (! class_exists($typeName)) {
                continue;
            }

            if (is_subclass_of($typeName, Relation::class) || $typeName === Relation::class) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * Semua model Eloquent di app/Domain.
     *
     * @return array<int, class-string<Model>>
     */
    private function models(): array
    {
        $models = [];

        foreach ($this->phpFiles('app/Domain') as $path => $source) {
            $namespace = $this->namespaceOf($source);

            if (! preg_match('/^(?:final\s+|abstract\s+)?class\s+([A-Za-z0-9_]+)/m', $source, $m)) {
                continue;
            }

            $class = $namespace.'\\'.$m[1];

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * @return array<string, string> path => source
     */
    private function phpFiles(string $root = 'app'): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path($root))
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file->getPathname());
            $files[$relative] = file_get_contents($file->getPathname());
        }

        return $files;
    }

    private function namespaceOf(string $source): string
    {
        preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $source, $m);

        return $m[1] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function aliasesOf(string $source): array
    {
        preg_match_all(
            '/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/m',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $aliases = [];

        foreach ($matches as $m) {
            $fqcn = $m[1];
            $position = strrpos($fqcn, '\\');
            $alias = $m[2] ?? substr($fqcn, $position === false ? 0 : $position + 1);
            $aliases[$alias] = $fqcn;
        }

        return $aliases;
    }

    /**
     * @return array<int, string>
     */
    private function classReferencesIn(string $source): array
    {
        preg_match_all(
            '/(?:\\\\?)([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)::class/',
            $source,
            $matches,
        );

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private function resolves(string $ref, string $namespace, array $aliases): bool
    {
        if ($this->typeExists($ref)) {
            return true;
        }

        $head = strstr($ref, '\\', true) ?: $ref;
        $tail = strstr($ref, '\\') ?: '';

        if (isset($aliases[$head]) && $this->typeExists($aliases[$head].$tail)) {
            return true;
        }

        return $namespace !== '' && $this->typeExists($namespace.'\\'.$ref);
    }

    private function typeExists(string $type): bool
    {
        return class_exists($type)
            || interface_exists($type)
            || trait_exists($type)
            || enum_exists($type);
    }
}
