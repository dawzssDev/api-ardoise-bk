<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 1 = VIP sin límites; 0 = aplica límites del plan
            $table->unsignedTinyInteger('user_ardo_vip')->default(0)->after('stripe_customer_id');
            $table->unsignedInteger('limit_sucursales')->nullable()->after('user_ardo_vip');
            $table->unsignedInteger('limit_insumos')->nullable()->after('limit_sucursales');
            // Máximo de registros de stock por sucursal
            $table->unsignedInteger('limit_stock_insumos')->nullable()->after('limit_insumos');
            $table->unsignedInteger('limit_proveedores')->nullable()->after('limit_stock_insumos');
            $table->unsignedInteger('limit_productos')->nullable()->after('limit_proveedores');
            // Máximo de registros de stock por sucursal
            $table->unsignedInteger('limit_stock_productos')->nullable()->after('limit_productos');
            $table->unsignedInteger('limit_personal')->nullable()->after('limit_stock_productos');
            $table->unsignedInteger('limit_cuentas_contables')->nullable()->after('limit_personal');
            $table->unsignedInteger('limit_roles')->nullable()->after('limit_cuentas_contables');
            $table->unsignedInteger('limit_staff')->nullable()->after('limit_roles');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'user_ardo_vip',
                'limit_sucursales',
                'limit_insumos',
                'limit_stock_insumos',
                'limit_proveedores',
                'limit_productos',
                'limit_stock_productos',
                'limit_personal',
                'limit_cuentas_contables',
                'limit_roles',
                'limit_staff',
            ]);
        });
    }
};
