<?php

use App\Models\HomeHero;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Strip a stray trailing full stop from the homepage hero tagline (and eyebrow)
 * that some admin edits left after "European Heritage Sports and Lifestyle House".
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const COLUMNS = ['tagline', 'eyebrow'];

    public function up(): void
    {
        $hero = HomeHero::query()->first();

        if ($hero === null) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            $value = (string) $hero->getAttribute($column);
            $trimmed = $this->trimTrailingPeriod($value);

            if ($trimmed !== $value) {
                $hero->fill([$column => $trimmed])->saveQuietly();
            }

            $rows = DB::table('translations')
                ->where('translatable_type', HomeHero::class)
                ->where('translatable_id', $hero->id)
                ->where('column', $column)
                ->get();

            foreach ($rows as $row) {
                if (! is_string($row->value)) {
                    continue;
                }

                $fixed = $this->trimTrailingPeriod($row->value);

                if ($fixed === $row->value) {
                    continue;
                }

                DB::table('translations')
                    ->where('id', $row->id)
                    ->update([
                        'value' => $fixed,
                        'source_hash' => hash('sha256', (string) $hero->getAttribute($column)),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        //
    }

    private function trimTrailingPeriod(string $value): string
    {
        return rtrim(rtrim($value), '.');
    }
};
