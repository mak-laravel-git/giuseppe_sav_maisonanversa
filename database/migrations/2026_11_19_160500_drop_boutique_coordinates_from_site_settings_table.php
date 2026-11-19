<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * There is no atelier or shop, so the contact page has no map. The Antwerp
 * coordinates and the OpenStreetMap embed built from them must not remain in
 * settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->dropColumn(['boutique_lat', 'boutique_lng']);
        });
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->decimal('boutique_lat', 10, 7)->default(51.220);
            $table->decimal('boutique_lng', 10, 7)->default(4.404);
        });
    }
};
