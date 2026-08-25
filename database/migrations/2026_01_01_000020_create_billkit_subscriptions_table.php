<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('billkit_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('billable');
            $table->string('type')->default('default');
            $table->string('billkit_id')->unique();
            $table->string('billkit_customer_id')->nullable()->index();
            $table->string('price_id')->nullable();
            $table->string('status');
            $table->string('renewal_state')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billkit_subscriptions');
    }
};
