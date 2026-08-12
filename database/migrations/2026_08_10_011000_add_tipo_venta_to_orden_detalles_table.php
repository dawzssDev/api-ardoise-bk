<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->foreignId('tipo_venta_id')
                ->nullable()
                ->after('producto_id')
                ->constrained('tb_tipos_venta')
                ->nullOnDelete();
            $table->decimal('precio_lista', 10, 2)
                ->nullable()
                ->after('quantity');
            $table->index('tipo_venta_id');
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_venta_id');
            $table->dropColumn('precio_lista');
        });
    }
};
