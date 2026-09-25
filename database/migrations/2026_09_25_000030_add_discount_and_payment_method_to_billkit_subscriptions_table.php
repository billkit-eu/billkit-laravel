<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror the subscription's `discount` and `payment_method` (added in 0.8.0).
 *
 * A new migration rather than an edit to the create-table one, because that
 * one has already run in every installed app and would never run again.
 *
 * The card columns sit on the subscription, not on the billable as Cashier's
 * `pm_type` / `pm_last_four` do: a BillKit mandate belongs to a subscription,
 * so a customer with two subscriptions can renew them on two different cards.
 */
return new class () extends Migration {
    /** @var list<string> */
    private array $columns = [
        'coupon_id',
        'discount_ends_at',
        'payment_method_type',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
    ];

    public function up(): void
    {
        if (Schema::hasColumn('billkit_subscriptions', 'coupon_id')) {
            return;
        }

        Schema::table('billkit_subscriptions', function (Blueprint $table): void {
            $table->string('coupon_id')->nullable();
            $table->timestamp('discount_ends_at')->nullable();
            $table->string('payment_method_type')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedTinyInteger('card_exp_month')->nullable();
            $table->unsignedSmallInteger('card_exp_year')->nullable();
        });
    }

    public function down(): void
    {
        $present = array_values(array_filter(
            $this->columns,
            static fn (string $column): bool => Schema::hasColumn('billkit_subscriptions', $column),
        ));
        if ($present === []) {
            return;
        }

        Schema::table('billkit_subscriptions', function (Blueprint $table) use ($present): void {
            $table->dropColumn($present);
        });
    }
};
