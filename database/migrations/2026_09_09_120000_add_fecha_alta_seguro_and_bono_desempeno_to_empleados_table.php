<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->date('fecha_alta_seguro')->nullable()->after('hire_date');
            $table->decimal('bono_desempeno', 12, 2)->nullable()->after('salary_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['fecha_alta_seguro', 'bono_desempeno']);
        });
    }
};
