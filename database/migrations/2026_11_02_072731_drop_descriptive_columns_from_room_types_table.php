<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn(['code', 'capacity', 'base_price', 'description', 'area', 'beds_count', 'amenities']);
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('code')->unique()->after('id');
            $table->unsignedTinyInteger('capacity')->default(1)->after('name');
            $table->decimal('base_price', 10, 2)->default(0)->after('capacity');
            $table->text('description')->nullable()->after('base_price');
            $table->unsignedInteger('area')->nullable()->after('description');
            $table->unsignedTinyInteger('beds_count')->nullable()->after('area');
            $table->longText('amenities')->nullable()->after('beds_count');
        });
    }
};
