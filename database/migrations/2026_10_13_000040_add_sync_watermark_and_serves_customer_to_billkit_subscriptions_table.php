<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns on `billkit_subscriptions` (added in 0.10.0).
 *
 * `last_event_created` is the `created` (epoch seconds) of the newest webhook
 * event applied to the row. Deliveries are unordered and retried
 * independently, so a retried `subscription.updated` from hours ago can land
 * after the `subscription.canceled` that superseded it; the webhook controller
 * skips any event older than this watermark. It is an integer rather than a
 * timestamp so the comparison is exact and never passes through a timezone.
 *
 * `serves_customer` mirrors the API's own entitlement answer, which is what
 * `Subscription::valid()` reads.
 *
 * A new migration rather than an edit to an existing one, because those have
 * already run in every installed app and would never run again.
 */
return new class () extends Migration {
    /** @var list<string> */
    private array $columns = ['last_event_created', 'serves_customer'];

    public function up(): void
    {
        if (Schema::hasColumn('billkit_subscriptions', 'last_event_created')) {
            return;
        }

        Schema::table('billkit_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_event_created')->nullable();
            $table->boolean('serves_customer')->nullable();
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
