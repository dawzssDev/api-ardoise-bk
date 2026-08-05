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

        Schema::table('ordenes', function (Blueprint $table) {
            if (! Schema::hasColumn('ordenes', 'preparacion_started_at')) {
                $table->timestamp('preparacion_started_at')->nullable()->after('finished_at');
            }
            if (! Schema::hasColumn('ordenes', 'listo_at')) {
                $table->timestamp('listo_at')->nullable()->after('preparacion_started_at');
            }
            if (! Schema::hasColumn('ordenes', 'seconds_in_nuevo')) {
                $table->unsignedInteger('seconds_in_nuevo')->nullable()->after('listo_at');
            }
            if (! Schema::hasColumn('ordenes', 'seconds_in_preparacion')) {
                $table->unsignedInteger('seconds_in_preparacion')->nullable()->after('seconds_in_nuevo');
            }
            if (! Schema::hasColumn('ordenes', 'seconds_total_listo')) {
                $table->unsignedInteger('seconds_total_listo')->nullable()->after('seconds_in_preparacion');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ordenes')) {
            return;
        }

        Schema::table('ordenes', function (Blueprint $table) {
            foreach ([
                'preparacion_started_at',
                'listo_at',
                'seconds_in_nuevo',
                'seconds_in_preparacion',
                'seconds_total_listo',
            ] as $column) {
                if (Schema::hasColumn('ordenes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
