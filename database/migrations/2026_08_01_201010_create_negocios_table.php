<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('negocios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 30);
            $table->boolean('needs_invoice')->default(false);
            // Datos fiscales opcionales (CFDI)
            $table->string('rfc', 13)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('tax_regime', 10)->nullable();
            $table->string('tax_zip', 10)->nullable();
            $table->string('cfdi_use', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('negocios');
    }
};
