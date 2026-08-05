<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ordenes')) {
            return;
        }

        $indexNames = collect(Schema::getIndexes('ordenes'))->pluck('name');

        if ($indexNames->contains('ordenes_sucursal_id_order_number_unique')) {
            return;
        }

        Schema::table('ordenes', function (Blueprint $table) use ($indexNames) {
            if ($indexNames->contains('ordenes_negocio_id_order_number_unique')) {
                $table->dropUnique('ordenes_negocio_id_order_number_unique');
            }

            $table->unique(['sucursal_id', 'order_number'], 'ordenes_sucursal_id_order_number_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ordenes')) {
            return;
        }

        $indexNames = collect(Schema::getIndexes('ordenes'))->pluck('name');

        if (! $indexNames->contains('ordenes_sucursal_id_order_number_unique')) {
            return;
        }

        Schema::table('ordenes', function (Blueprint $table) use ($indexNames) {
            $table->dropUnique('ordenes_sucursal_id_order_number_unique');

            if (! $indexNames->contains('ordenes_negocio_id_order_number_unique')) {
                $table->unique(['negocio_id', 'order_number'], 'ordenes_negocio_id_order_number_unique');
            }
        });
    }
};
