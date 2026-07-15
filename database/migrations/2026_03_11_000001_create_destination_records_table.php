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
            $table->string('external_id')->unique();
            $table->unsignedInteger('version');
            $table->dateTime('source_updated_at');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_records');
    }
};
