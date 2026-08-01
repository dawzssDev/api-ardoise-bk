<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('insumos')) {
            return;
        }

        if (Schema::hasColumn('insumos', 'categoria_insumo_id')) {
            return;
        }

        Schema::table('insumos', function (Blueprint $table) {
            $table->foreignId('categoria_insumo_id')
                ->after('negocio_id')
                ->constrained('categoria_insumos')
                ->restrictOnDelete();

            $table->index(['negocio_id', 'categoria_insumo_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('insumos') || ! Schema::hasColumn('insumos', 'categoria_insumo_id')) {
            return;
        }

        Schema::table('insumos', function (Blueprint $table) {
            $table->dropForeign(['categoria_insumo_id']);
            $table->dropIndex(['negocio_id', 'categoria_insumo_id']);
            $table->dropColumn('categoria_insumo_id');
        });
    }
};
