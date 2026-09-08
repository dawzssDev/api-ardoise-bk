<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->decimal('total_pagos_con_deposito', 12, 2)->nullable()->after('total_depositos_efectivo');
        });

        Schema::table('tb_turnos_cajas_cortes', function (Blueprint $table) {
            $table->decimal('total_pagos_con_deposito', 12, 2)->nullable()->after('total_depositos_efectivo');
        });

        Schema::table('tb_gastos_en_turno', function (Blueprint $table) {
            $table->string('origen', 20)->nullable()->after('proveedor_id');
            $table->index(['turno_caja_id', 'origen']);
        });
    }

    public function down(): void
    {
        Schema::table('tb_gastos_en_turno', function (Blueprint $table) {
            $table->dropIndex(['turno_caja_id', 'origen']);
            $table->dropColumn('origen');
        });

        Schema::table('tb_turnos_cajas_cortes', function (Blueprint $table) {
            $table->dropColumn('total_pagos_con_deposito');
        });

        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropColumn('total_pagos_con_deposito');
        });
    }
};
