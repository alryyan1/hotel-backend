<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('price_usd', 10, 2)->nullable()->after('room_type_id');
            $table->decimal('price_sdg', 10, 2)->nullable()->after('price_usd');
        });

        // The existing single `price` column held SDG amounts despite the old UI's "$" label
        DB::statement('UPDATE rooms SET price_sdg = price');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('room_type_id');
        });

        DB::statement('UPDATE rooms SET price = price_sdg');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['price_usd', 'price_sdg']);
        });
    }
};
