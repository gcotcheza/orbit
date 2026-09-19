<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The window's tripwire, stored beside the summary it is about: how many of a band's fares
 * depart beyond the far horizon and what they cost (docs/BUSINESS-LOGIC.md §15, R10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_price_stats', function (Blueprint $table): void {
            // Default 0 rather than nullable: "none beyond the horizon" is an
            // answer every refresh has, and a null would read as "not measured".
            $table->unsignedInteger('far_count')->default(0);
            $table->unsignedInteger('far_median_cents')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('return_price_stats', function (Blueprint $table): void {
            $table->dropColumn(['far_count', 'far_median_cents']);
        });
    }
};
