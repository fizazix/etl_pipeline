<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_errors', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->default('');
            $table->string('source_cursor');
            $table->json('raw_payload');
            $table->text('error_message');
            $table->string('error_code');
            $table->timestamps();

            $table->unique(['external_id', 'source_cursor', 'error_code'], 'ingestion_errors_idempotent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_errors');
    }
};
