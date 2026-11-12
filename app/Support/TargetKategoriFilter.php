<?php

namespace App\Support;

use Illuminate\Support\Collection;

class TargetKategoriFilter
{
    /**
     * Filter a collection of Generus models matching any target_kategori criteria.
     */
    public static function filter(Collection $generusCollection, array $targetKategori): Collection
    {
        if (empty($targetKategori)) {
            return $generusCollection;
        }

        $normalizedTargets = array_map(
            static fn ($target) => strtolower((string) $target),
            $targetKategori
        );

        return $generusCollection->filter(static function ($g) use ($normalizedTargets) {
            $jenjangLower = strtolower((string) ($g->jenjang ?? ''));

            foreach ($normalizedTargets as $tLower) {
                if ($tLower === 'pengurus' || $tLower === 'pengurus usman') {
                    if ($g->is_pengurus) {
                        return true;
                    }
                } elseif ($tLower === 'pengurus muda mudi') {
                    if ($g->is_pengurus_muda_mudi) {
                        return true;
                    }
                } elseif ($tLower === 'ketua/wakil kelompok') {
                    if ($g->is_ketua_wakil_kelompok) {
                        return true;
                    }
                } elseif (
                    $g->pengurusTypes
                    && $g->pengurusTypes->contains(
                        static fn ($pt) => strtolower($pt->name) === $tLower || strtolower($pt->code) === $tLower
                    )
                ) {
                    return true;
                } elseif ($tLower !== '' && str_contains($jenjangLower, $tLower)) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
