<?php

namespace App\Console\Commands\Product;

use App\Models\Faq;
use App\Models\Translation;
use Database\Seeders\FaqSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Previews and applies the client's "FAQ — new texts (NL · EN · FR)" list (26 Nov 2026).
 *
 * The texts and the writing live in FaqSeeder; this command adds the preview the
 * seeder cannot offer. Only the contact page FAQ is touched.
 */
#[Signature('faq:apply-contact-copy {--dry-run : Show what would change without writing} {--diff : Print the current text next to the new text for every text that changes}')]
#[Description('Replace the contact page FAQ with the client-approved NL/EN/FR wording')]
class ApplyContactFaqCopy extends Command
{
    public function handle(): int
    {
        $existing = FaqSeeder::existingContactFaqs();
        $entries = FaqSeeder::contactFaqs();

        if ($existing->count() > count($entries)) {
            $this->table(
                ['FAQ id', 'Sort order', 'Published', 'Question'],
                $existing->map(fn (Faq $faq): array => [
                    $faq->id,
                    $faq->sort_order,
                    $faq->is_published ? 'yes' : 'no',
                    $faq->question,
                ])->all(),
            );
            $this->error("Found {$existing->count()} contact FAQs, expected at most ".count($entries).'. Nothing was written. Remove the extra questions in the admin first.');

            return self::FAILURE;
        }

        $rows = [];
        $changes = 0;

        foreach ($entries as $position => $entry) {
            $faq = $existing->get($position);
            $number = $position + 1;

            foreach ($entry as $locale => $texts) {
                foreach ($texts as $column => $new) {
                    $status = match (true) {
                        $faq === null => 'create',
                        $this->isApplied($faq, $position, $locale, $column, $entry) => 'unchanged',
                        default => 'update',
                    };

                    $current = $faq?->translated($column, $locale);

                    if ($this->option('diff') && $current !== $new) {
                        $this->line("<fg=yellow>{$number} · {$locale} · {$column}</>");
                        $this->line('  now: '.($current ?? '(no row)'));
                        $this->line('  new: '.$new);
                    }

                    $rows[] = [$number, $faq?->id ?? 'new', $locale, $column, $status];
                    $changes += $status === 'unchanged' ? 0 : 1;
                }
            }
        }

        $this->table(['Question', 'FAQ id', 'Locale', 'Field', 'Status'], $rows);

        if ($this->option('dry-run')) {
            $this->info("{$changes} of ".count($rows).' texts would change. Dry run: nothing was written.');

            return self::SUCCESS;
        }

        if ($this->callSilent('db:seed', ['--class' => FaqSeeder::class, '--force' => true]) !== self::SUCCESS) {
            $this->error('FaqSeeder failed.');

            return self::FAILURE;
        }

        $this->info("Updated {$changes} of ".count($rows).' texts.');

        return self::SUCCESS;
    }

    /**
     * Whether the seeder would leave this text, and the row that holds it, as they are.
     *
     * @param  array<string, array<string, string>>  $entry
     */
    private function isApplied(Faq $faq, int $position, string $locale, string $column, array $entry): bool
    {
        $translation = $faq->translations->first(
            fn (Translation $translation): bool => $translation->locale === $locale
                && $translation->column === $column,
        );

        return $faq->getAttribute($column) === $entry['nl'][$column]
            && $faq->sort_order === $position
            && $faq->is_published
            && $translation?->value === $entry[$locale][$column]
            && $translation->source_hash === $faq->translationSourceHash($column);
    }
}
