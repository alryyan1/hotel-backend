<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $indexes = collect(\DB::select("SHOW INDEX FROM contest_participants"))->pluck('Key_name')->toArray();

        Schema::table('contest_participants', function (Blueprint $table) use ($indexes) {
            if (!in_array('contest_participants_full_name_unique', $indexes)) {
                $table->unique('full_name');
            }
            if (!in_array('contest_participants_phone_number_unique', $indexes)) {
                $table->unique('phone_number');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contest_participants', function (Blueprint $table) {
            $table->dropUnique(['full_name']);
            $table->dropUnique(['phone_number']);
        });
    }
};
