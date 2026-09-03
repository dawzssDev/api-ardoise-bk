<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_start')->nullable()->after('status');
            $table->boolean('cancel_at_period_end')->default(false)->after('current_period_end');
            $table->timestamp('access_until')->nullable()->after('cancel_at_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'current_period_start',
                'cancel_at_period_end',
                'access_until',
            ]);
        });
    }
};
