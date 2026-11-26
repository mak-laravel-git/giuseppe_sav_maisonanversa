<?php

namespace App\Console\Commands\Product;

use App\Models\StoryPage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Applies the client's "Our Story — lines to replace (EN and FR)" list (25 Nov 2026).
 *
 * Only the English and French translation rows listed in the data file are written;
 * the Dutch source, every other column and every other page stay untouched. The
 * rows carry the current source hash, so DeepL does not translate them again.
 */
#[Signature('story:apply-copy-fixes {--file= : JSON file with the lines to replace} {--dry-run : Show what would change without writing} {--diff : Print the current text next to the new text for every line that changes}')]
#[Description('Replace the listed Our Story EN/FR lines with the client-approved wording')]
class ApplyStoryCopyFixes extends Command
{
    private const DEFAULT_FILE = 'data/client-story-fixes-2026-11-25.json';

    public function handle(): int
    {
        $page = StoryPage::query()->first();

        if ($page === null) {
            $this->error('There is no Our Story page in this database.');

            return self::FAILURE;
        }

        $path = $this->option('file') ?: database_path(self::DEFAULT_FILE);

        if (! is_file($path)) {
            $this->error("Data file not found: {$path}");

            return self::FAILURE;
        }

        /** @var array<string, array<string, array{from: string, to: string}>> $fixes */
        $fixes = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $rows = [];
        $writes = [];

        foreach ($fixes as $locale => $columns) {
            foreach ($columns as $column => $fix) {
                if (! in_array($column, StoryPage::TRANSLATION_COLUMNS, true)) {
                    $this->error("Unknown Our Story column: {$column}");

                    return self::FAILURE;
                }

                $current = DB::table('translations')
                    ->where('translatable_type', StoryPage::class)
                    ->where('translatable_id', $page->id)
                    ->where('locale', $locale)
                    ->where('column', $column)
                    ->value('value');

                $status = match (true) {
                    $current === $fix['to'] => 'already approved',
                    $current === null => 'update (no row yet: the page shows the source text)',
                    $this->same((string) $current, $fix['from']) => 'update',
                    default => 'update (differs from the list)',
                };

                if ($this->option('diff') && $current !== $fix['to']) {
                    $this->line("<fg=yellow>{$locale} · {$column}</>");
                    $this->line('  now: '.($current ?? '(no row)'));
                    $this->line('  new: '.$fix['to']);
                }

                $rows[] = [$locale, $column, $status];

                if ($current !== $fix['to']) {
                    $writes[] = [$locale, $column, $fix['to']];
                }
            }
        }

        $this->table(['Locale', 'Column', 'Status'], $rows);

        if ($this->option('dry-run')) {
            $this->info(count($writes).' of '.count($rows).' lines would change. Dry run: nothing was written.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($page, $writes): void {
            foreach ($writes as [$locale, $column, $value]) {
                DB::table('translations')->updateOrInsert(
                    [
                        'translatable_type' => StoryPage::class,
                        'translatable_id' => $page->id,
                        'locale' => $locale,
                        'column' => $column,
                    ],
                    [
                        'value' => $value,
                        'source_hash' => $page->translationSourceHash($column),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        $this->info('Updated '.count($writes).' of '.count($rows).' lines.');

        return self::SUCCESS;
    }

    /** Compares two texts ignoring the apostrophe style, which the client does not count. */
    private function same(string $a, string $b): bool
    {
        $normalise = fn (string $text): string => str_replace(['’', '‘'], "'", $text);

        return $normalise($a) === $normalise($b);
    }
}
