<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<array{name: string, length: int}>
     */
    private function columns(): array
    {
        return [
            ['name' => 'dp_Folio', 'length' => 255],
            ['name' => 'dp_Categoria', 'length' => 255],
            ['name' => 'cce_momento_pedido', 'length' => 255],
            ['name' => 'cce_dias_entrega', 'length' => 255],
            ['name' => 'cce_envioDom_costo', 'length' => 255],
            ['name' => 'cce_forma_pago', 'length' => 255],
            ['name' => 'cce_condiciones_pagos', 'length' => 255],
            ['name' => 'cce_solicitar_factura', 'length' => 255],
            ['name' => 'cce_pedido_min', 'length' => 255],
            ['name' => 'cce_descansos', 'length' => 255],
            ['name' => 'cce_tiempo_entrega', 'length' => 255],
            ['name' => 'cce_descuento_pVolumen', 'length' => 255],
            ['name' => 'cce_lugar_entrega', 'length' => 255],
            ['name' => 'cce_frecuencia_pedido', 'length' => 255],
            ['name' => 'cce_NoTarjetaClave', 'length' => 255],
            ['name' => 'cce_banco', 'length' => 255],
            ['name' => 'cce_propietario', 'length' => 255],
            ['name' => 'InsumosPrecios', 'length' => 8000],
            ['name' => 'Incidencias', 'length' => 500],
        ];
    }

    public function up(): void
    {
        $after = 'notes';

        foreach ($this->columns() as $column) {
            if (Schema::hasColumn('proveedores', $column['name'])) {
                $after = $column['name'];

                continue;
            }

            Schema::table('proveedores', function (Blueprint $table) use ($column, $after) {
                $table->string($column['name'], $column['length'])->nullable()->after($after);
            });

            $after = $column['name'];
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            array_map(
                fn (array $column): ?string => Schema::hasColumn('proveedores', $column['name']) ? $column['name'] : null,
                $this->columns(),
            ),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('proveedores', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
