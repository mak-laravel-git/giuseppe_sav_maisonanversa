<?php

use App\Models\StoryPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DeepL mistranslated Dutch "de baan" (padel court) as job/track/course.
 * Pin interim EN/FR court wording for curated Story columns and stop DeepL
 * from overwriting them until the client's exact Ons Verhaal file is applied.
 */
return new class extends Migration
{
    /**
     * Columns that mention the court / "de baan" and must not be auto-translated.
     *
     * @var list<string>
     */
    private const CURATED = [
        'hero_body',
        'ritual_step_4_title',
        'ritual_step_4_body',
        'ritual_footer',
        'origins_italic',
        'founder_paragraph_two',
    ];

    /**
     * Known bad fragments → padel-court wording (interim until client copy lands).
     *
     * @var array<string, array{en: array<string, string>, fr: array<string, string>}>
     */
    private const EXPLICIT = [
        'ritual_step_4_title' => [
            'en' => 'The court',
            'fr' => 'Le terrain',
        ],
        'ritual_step_4_body' => [
            'en' => 'Sportswear on. And play.',
            'fr' => 'Tenue de sport. Et jouer.',
        ],
    ];

    public function up(): void
    {
        $page = StoryPage::query()->first();

        if ($page === null) {
            return;
        }

        foreach (self::CURATED as $column) {
            if (isset(self::EXPLICIT[$column])) {
                $this->upsert($page, $column, 'en', self::EXPLICIT[$column]['en']);
                $this->upsert($page, $column, 'fr', self::EXPLICIT[$column]['fr']);

                continue;
            }

            foreach (['en', 'fr'] as $locale) {
                $row = DB::table('translations')
                    ->where('translatable_type', StoryPage::class)
                    ->where('translatable_id', $page->id)
                    ->where('locale', $locale)
                    ->where('column', $column)
                    ->first();

                if ($row === null || ! is_string($row->value) || $row->value === '') {
                    continue;
                }

                $fixed = $this->fixCourtWording($row->value, $locale);

                if ($fixed !== $row->value) {
                    $this->upsert($page, $column, $locale, $fixed);
                }
            }
        }
    }

    public function down(): void
    {
        //
    }

    private function upsert(StoryPage $page, string $column, string $locale, string $value): void
    {
        $source = (string) $page->getAttribute($column);

        DB::table('translations')->updateOrInsert(
            [
                'translatable_type' => StoryPage::class,
                'translatable_id' => $page->id,
                'locale' => $locale,
                'column' => $column,
            ],
            [
                'value' => $value,
                'source_hash' => hash('sha256', $source),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function fixCourtWording(string $value, string $locale): string
    {
        if ($locale === 'en') {
            $replacements = [
                'left your job' => 'left the court',
                'the job' => 'the court',
                'The track' => 'The court',
                'the track' => 'the court',
                'the course' => 'the court',
                'The course' => 'The court',
            ];
        } else {
            $replacements = [
                'quitte son emploi' => 'quitte le terrain',
                'le travail' => 'le terrain',
                'Le travail' => 'Le terrain',
                'Le parcours' => 'Le terrain',
                'le parcours' => 'le terrain',
            ];
        }

        return str_replace(array_keys($replacements), array_values($replacements), $value);
    }
};
