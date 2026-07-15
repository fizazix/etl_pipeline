<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_records', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->string('status');
            $table->unsignedBigInteger('version');
            $table->timestamp('source_updated_at', 6);
            $table->json('raw_payload');
            $table->timestamps();

            $table->index('status');
            $table->index('source_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_records');
    }
};
