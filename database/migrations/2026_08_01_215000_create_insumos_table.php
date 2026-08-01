<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('categoria_insumo_id')->constrained('categoria_insumos')->restrictOnDelete();
            $table->string('name');
            $table->boolean('status_insumo')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['negocio_id', 'name']);
            $table->index(['negocio_id', 'status_insumo']);
            $table->index(['negocio_id', 'categoria_insumo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumos');
    }
};
