<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mae_cuenta_conta_suc_detalle', function (Blueprint $table) {
            $table->foreignId('cuenta_origen_id')
                ->nullable()
                ->after('tipo_movimiento')
                ->constrained('mae_cuenta_conta_suc')
                ->restrictOnDelete();
            $table->decimal('monto_movimiento', 12, 2)
                ->default(0)
                ->after('cuenta_origen_id');
            $table->foreignId('cuenta_destino_id')
                ->nullable()
                ->after('monto_movimiento')
                ->constrained('mae_cuenta_conta_suc')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mae_cuenta_conta_suc_detalle', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_origen_id');
            $table->dropConstrainedForeignId('cuenta_destino_id');
            $table->dropColumn('monto_movimiento');
        });
    }
};
