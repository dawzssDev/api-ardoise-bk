<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_insumos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('insumo_id')->constrained('insumos')->cascadeOnDelete();
            $table->decimal('stock_fisico', 14, 3)->default(0);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un stock por insumo en cada sucursal/bodega
            $table->unique(['sucursal_id', 'insumo_id']);
            $table->index(['negocio_id', 'sucursal_id']);
            $table->index(['negocio_id', 'insumo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_insumos');
    }
};
