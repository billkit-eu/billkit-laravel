<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $table = $this->billableTable();
        if (! Schema::hasColumn($table, 'billkit_customer_id')) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('billkit_customer_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $table = $this->billableTable();
        if (Schema::hasColumn($table, 'billkit_customer_id')) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('billkit_customer_id');
            });
        }
    }

    private function billableTable(): string
    {
        $model = config('billkit.model');
        if (is_string($model) && class_exists($model)) {
            $instance = new $model();
            if ($instance instanceof Illuminate\Database\Eloquent\Model) {
                return $instance->getTable();
            }
        }

        return 'users';
    }
};
