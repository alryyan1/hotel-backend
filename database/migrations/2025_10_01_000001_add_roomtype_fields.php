<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->unsignedInteger('area')->nullable()->after('description');
            $table->unsignedTinyInteger('beds_count')->nullable()->after('area');
            $table->json('amenities')->nullable()->after('beds_count');
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['area', 'beds_count', 'amenities']);
        });
    }
};


