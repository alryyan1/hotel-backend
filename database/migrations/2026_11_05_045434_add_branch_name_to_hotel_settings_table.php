<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table) {
            $table->string('branch_name')->nullable()->after('official_name');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table) {
            $table->dropColumn('branch_name');
        });
    }
};
