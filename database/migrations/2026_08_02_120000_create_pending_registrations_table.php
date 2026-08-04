<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->string('email');
            $table->string('password');
            $table->string('name');
            $table->string('business_name');
            $table->string('phone', 30);
            $table->boolean('needs_invoice')->default(false);
            $table->string('rfc', 13)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('tax_regime', 10)->nullable();
            $table->string('tax_zip', 10)->nullable();
            $table->string('cfdi_use', 10)->nullable();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('stripe_price_id')->nullable();
            $table->string('status', 30)->default('pending'); // pending|checkout|completed|expired
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['email', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
