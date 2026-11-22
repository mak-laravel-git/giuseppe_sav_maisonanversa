<?php

use App\Models\StoryPage;

test('story court columns are curated and skipped by DeepL', function () {
    expect(StoryPage::MANUALLY_CURATED_COLUMNS)->toContain('ritual_step_4_title')
        ->and(StoryPage::MANUALLY_CURATED_COLUMNS)->toContain('founder_paragraph_two');

    $page = new StoryPage;

    expect($page->translatableColumns())
        ->not->toContain('ritual_step_4_title')
        ->not->toContain('hero_body')
        ->toContain('city_title');
});
