<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categoria_insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->unique(['negocio_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categoria_insumos');
    }
};
