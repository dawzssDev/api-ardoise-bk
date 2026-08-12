<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_tipos_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->constrained('negocios')->cascadeOnDelete();
            $table->string('name');
            $table->enum('tipo_descuento', ['ninguno', 'porcentaje', 'monto_fijo', 'gratis']);
            $table->decimal('valor_descuento', 10, 2)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['negocio_id', 'name']);
            $table->index(['negocio_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_tipos_venta');
    }
};
