<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->foreignId('sucursal_id')->constrained('sucursales')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->decimal('stock_fisico', 14, 3)->default(0);
            $table->decimal('stock_minimo', 14, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['sucursal_id', 'producto_id']);
            $table->index(['negocio_id', 'sucursal_id']);
            $table->index(['negocio_id', 'producto_id']);
            $table->index(['sucursal_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_productos');
    }
};
