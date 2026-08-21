<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_gastos_en_turno', function (Blueprint $table) {
            $table->foreignId('proveedor_id')
                ->nullable()
                ->after('tipo_gasto')
                ->constrained('proveedores')
                ->restrictOnDelete();

            $table->index(['negocio_id', 'proveedor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_gastos_en_turno', function (Blueprint $table) {
            $table->dropIndex(['negocio_id', 'proveedor_id']);
            $table->dropConstrainedForeignId('proveedor_id');
        });
    }
};
