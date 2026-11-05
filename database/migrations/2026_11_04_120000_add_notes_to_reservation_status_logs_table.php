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
        Schema::table('reservation_status_logs', function (Blueprint $table) {
            $table->string('to_status')->nullable()->change();
            $table->text('notes')->nullable()->after('to_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_status_logs', function (Blueprint $table) {
            $table->dropColumn('notes');
            $table->string('to_status')->nullable(false)->change();
        });
    }
};
