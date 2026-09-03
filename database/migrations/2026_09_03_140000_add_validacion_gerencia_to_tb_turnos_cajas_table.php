<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
        $table->decimal('efectivo_gerencia', 12, 2)->nullable()->after('status_gerencia');
            $table->decimal('terminal_gerencia', 12, 2)->nullable()->after('efectivo_gerencia');
            $table->decimal('diferencia_gerencia', 12, 2)->nullable()->after('terminal_gerencia');
            $table->timestamp('date_validation_gerencia')->nullable()->after('diferencia_gerencia');
            $table->unsignedBigInteger('user_id_date_validation_gerencia')->nullable()->after('date_validation_gerencia');
            $table->foreign('user_id_date_validation_gerencia', 'tb_turnos_cajas_user_val_ger_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tb_turnos_cajas', function (Blueprint $table) {
            $table->dropForeign('tb_turnos_cajas_user_val_ger_fk');
            $table->dropColumn([
                'efectivo_gerencia',
                'terminal_gerencia',
                'diferencia_gerencia',
                'date_validation_gerencia',
                'user_id_date_validation_gerencia',
            ]);
        });
    }
};
