<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('call_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('call_sid')->unique();
            $table->string('step')->default('welcome');
            $table->string('family')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requested_date')->nullable();
            $table->string('requested_time')->nullable();
            $table->string('customer_phone')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_contexts');
    }
};
