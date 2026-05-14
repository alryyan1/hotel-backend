<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table) {
            $table->string('bank_account_number_1')->nullable()->after('email');
            $table->string('bank_account_name_1')->nullable()->after('bank_account_number_1');
            $table->string('bank_account_number_2')->nullable()->after('bank_account_name_1');
            $table->string('bank_account_name_2')->nullable()->after('bank_account_number_2');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table) {
            $table->dropColumn(['bank_account_number_1', 'bank_account_name_1', 'bank_account_number_2', 'bank_account_name_2']);
        });
    }
};
