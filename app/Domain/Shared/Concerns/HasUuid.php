<?php

declare(strict_types=1);

namespace App\Domain\Shared\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Setiap tabel memakai `id` bigint auto-increment untuk relasi internal, plus
 * kolom `uuid` yang dipakai di API dan URL.
 *
 * Alasannya bukan estetika. Auto-increment yang bocor ke publik memberi tahu
 * pesaing berapa order yang kamu proses per hari, dan memungkinkan seseorang
 * menebak identifier order orang lain dengan menambah satu. Relasi internal
 * tetap memakai bigint karena index-nya jauh lebih kecil dan join-nya lebih
 * cepat daripada UUID.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->{$model->getUuidColumn()})) {
                $model->{$model->getUuidColumn()} = (string) Str::uuid7();
            }
        });
    }

    public function getUuidColumn(): string
    {
        return 'uuid';
    }

    /**
     * Route binding memakai uuid, bukan id. Dipasang lewat getRouteKeyName()
     * di model, bukan di sini, supaya model yang butuh binding lain tetap bisa.
     */
    public function scopeWhereUuid(Builder $query, string $uuid): Builder
    {
        return $query->where($this->getUuidColumn(), $uuid);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? $this->getUuidColumn(), $value);
    }
}
