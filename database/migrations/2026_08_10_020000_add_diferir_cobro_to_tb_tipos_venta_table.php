<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_tipos_venta', function (Blueprint $table) {
            $table->boolean('diferir_cobro')->default(false)->after('valor_descuento');
            $table->boolean('requiere_empleado')->default(false)->after('diferir_cobro');
        });
    }

    public function down(): void
    {
        Schema::table('tb_tipos_venta', function (Blueprint $table) {
            $table->dropColumn(['diferir_cobro', 'requiere_empleado']);
        });
    }
};
