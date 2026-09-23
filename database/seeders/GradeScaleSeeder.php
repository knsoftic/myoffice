<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Institute\GradeScale;
use App\Models\Institute\GradeScaleBand;
use App\Services\Institute\GradeBandValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The one scale an institute starts with (phase-19-23 §6.9).
 *
 * **Idempotent, and it never overwrites.** A scale an administrator has edited is theirs: re-running
 * the seeders after a deploy must not restore the bands they deliberately changed. The `DEFAULT` scale
 * is created if it is missing and left completely alone if it is not — the same discipline
 * `SettingSeeder` uses, and for the same reason.
 *
 * **The bands are the contract's, verbatim**: A+ 90–100, A 80–89.99, B 70–79.99, C 60–69.99,
 * D 50–59.99, E 40–49.99 (the lowest pass), F 0–39.99. They are contiguous at two decimals, cover
 * exactly 0–100, and cross from fail to pass once — so `GradeBandValidator` accepts them, which is
 * worth knowing given it is the thing that would otherwise reject the system's own starting data.
 */
final class GradeScaleSeeder extends Seeder
{
    private const CODE = 'DEFAULT';

    /**
     * grade, title, min, max, grade point, pass.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: bool}>
     */
    private const BANDS = [
        ['A+', 'Outstanding', '90.00', '100.00', '4.00', true],
        ['A', 'Excellent', '80.00', '89.99', '3.70', true],
        ['B', 'Very good', '70.00', '79.99', '3.00', true],
        ['C', 'Good', '60.00', '69.99', '2.30', true],
        ['D', 'Satisfactory', '50.00', '59.99', '1.70', true],
        ['E', 'Pass', '40.00', '49.99', '1.00', true],
        ['F', 'Fail', '0.00', '39.99', '0.00', false],
    ];

    public function run(): void
    {
        if (GradeScale::query()->where('code', self::CODE)->exists()) {
            $this->command?->info('Grade scale DEFAULT already exists — left as it is.');

            return;
        }

        // The bands go past the same validator the service uses, before anything is written.
        //
        // The seeder writes rows directly rather than calling `GradeScaleService::create()`, because a
        // seeder has no actor and should not produce an activity record for an install step. That is
        // the D108 shape — a fixture assembling a state the application would not — so the geometry
        // check at least is shared. A default scale with a gap in it would grade the whole institute
        // on a ladder nobody could have created through the UI.
        app(GradeBandValidator::class)->validate(array_map(
            static fn (array $band): array => [
                'grade' => $band[0],
                'min_percentage' => $band[2],
                'max_percentage' => $band[3],
                'is_pass' => $band[5],
            ],
            self::BANDS,
        ));

        DB::transaction(function (): void {
            $scale = new GradeScale;
            $scale->forceFill([
                'code' => self::CODE,
                'name' => 'Standard percentage scale',
                'description' => 'Seven bands from A+ to F, with the pass line at 40%.',
                'pass_percentage' => '40.0000',
                // The seeder makes it the default because a fresh install with no default scale
                // cannot grade an exam that names none, and `resolveFor()` would rightly refuse.
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
                'bands_count' => count(self::BANDS),
            ]);
            $scale->save();

            foreach (self::BANDS as $index => [$grade, $title, $min, $max, $point, $pass]) {
                $band = new GradeScaleBand;
                $band->forceFill([
                    'grade_scale_id' => (int) $scale->getKey(),
                    'grade' => $grade,
                    'title' => $title,
                    'min_percentage' => $min,
                    'max_percentage' => $max,
                    'grade_point' => $point,
                    'is_pass' => $pass,
                    'color' => $pass ? ($index < 3 ? 'emerald' : 'sky') : 'rose',
                    // Lowest first, matching the order the validator sorts into.
                    'sort_order' => count(self::BANDS) - 1 - $index,
                ]);
                $band->save();
            }
        });

        $this->command?->info('Seeded the DEFAULT grade scale with '.count(self::BANDS).' bands.');
    }
}
