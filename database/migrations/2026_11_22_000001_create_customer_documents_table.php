<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Carry over the old single-document column so nothing already uploaded disappears
        $now = now();
        DB::table('customers')->whereNotNull('document_path')->orderBy('id')
            ->each(function ($c) use ($now) {
                DB::table('customer_documents')->insert([
                    'customer_id'   => $c->id,
                    'path'          => $c->document_path,
                    'original_name' => basename($c->document_path),
                    'mime_type'     => 'application/pdf',
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
    }
};
