<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('pipeline_name')->unique();
            $table->string('cursor')->nullable();
            $table->string('status')->default('idle');
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_checkpoints');
    }
};
