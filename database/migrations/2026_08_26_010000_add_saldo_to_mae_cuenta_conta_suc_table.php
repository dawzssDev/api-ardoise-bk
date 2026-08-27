<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mae_cuenta_conta_suc', function (Blueprint $table) {
            $table->decimal('saldo', 12, 2)->default(0)->after('descripcion_cuenta');
        });
    }

    public function down(): void
    {
        Schema::table('mae_cuenta_conta_suc', function (Blueprint $table) {
            $table->dropColumn('saldo');
        });
    }
};
