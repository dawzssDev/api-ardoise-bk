<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_insumos', 'is_active')) {
            Schema::table('stock_insumos', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('stock_minimo');
                $table->index(['sucursal_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('stock_insumos', 'is_active')) {
            return;
        }

        Schema::table('stock_insumos', function (Blueprint $table) {
            $table->dropIndex(['sucursal_id', 'is_active']);
            $table->dropColumn('is_active');
        });
    }
};
