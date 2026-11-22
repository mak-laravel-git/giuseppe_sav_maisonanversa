<?php

test('the house page renders the light house guide', function () {
    $this->get('/nl/huis')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('maison/house'));

    $page = file_get_contents(resource_path('js/pages/maison/house.tsx'));
    $guide = file_get_contents(resource_path('js/components/maison/house/maison-house-guide.tsx'));

    expect($page)
        ->toContain('<MaisonHouseGuide />')
        ->toContain('tone="cream"')
        ->not->toContain('<MaisonFloorplan />')
        ->and($guide)->toContain('doorHouseArt')
        ->and($guide)->toContain('RoomWindow')
        ->and($guide)->toContain('OPENING_ROOMS')
        ->and($guide)->toContain('HOUSE_LIST_ORDER');
});

test('the house guide keeps opening window hotspots and remaps list numerals', function () {
    $guide = file_get_contents(resource_path('js/components/maison/house/maison-house-guide.tsx'));

    expect($guide)
        ->toContain("display: 'I', opening: 'VI'")
        ->toContain("display: 'II', opening: 'VII'")
        ->toContain("display: 'III', opening: 'VIII'")
        ->toContain("display: 'IV', opening: 'III'")
        ->toContain("display: 'V', opening: 'IV'")
        ->toContain("display: 'VI', opening: 'V'")
        ->toContain("display: 'VII', opening: 'I'")
        ->toContain("display: 'VIII', opening: 'II'")
        ->toContain('roomHref')
        ->not->toContain('MaisonFloorplan');
});

test('the house page has no greeting, register, or entrance animation', function () {
    $page = file_get_contents(resource_path('js/pages/maison/house.tsx'));
    $guide = file_get_contents(resource_path('js/components/maison/house/maison-house-guide.tsx'));

    expect($page.$guide)
        ->not->toContain('OpeningRegister')
        ->not->toContain('op-greeting')
        ->not->toContain('maison-facade')
        ->and($guide)->toContain('is-done');
});
