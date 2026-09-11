<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('productos', 'descuento_stock_prod')) {
            Schema::table('productos', function (Blueprint $table) {
                $table->json('descuento_stock_prod')->nullable()->after('image');
            });
        }

        if (! Schema::hasColumn('productos', 'descuento_stock_insum')) {
            Schema::table('productos', function (Blueprint $table) {
                $after = Schema::hasColumn('productos', 'descuento_stock_prod')
                    ? 'descuento_stock_prod'
                    : 'image';
                $table->json('descuento_stock_insum')->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('productos', 'descuento_stock_insum') ? 'descuento_stock_insum' : null,
            Schema::hasColumn('productos', 'descuento_stock_prod') ? 'descuento_stock_prod' : null,
        ]));

        if ($columns === []) {
            return;
        }

        Schema::table('productos', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
