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
            $table->string('source_id')->nullable();
            $table->string('source_cursor')->nullable();
            $table->string('error_type');
            $table->json('error_details');
            $table->json('raw_payload');
            $table->char('fingerprint', 64)->unique();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('source_id');
            $table->index('error_type');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_errors');
    }
};
