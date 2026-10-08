<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $columns = Schema::getColumnListing('users');

        Schema::table('users', function (Blueprint $table) use ($columns) {
            if (! in_array('two_factor_secret', $columns, true)) {
                $table->text('two_factor_secret')->nullable();
            }

            if (! in_array('two_factor_recovery_codes', $columns, true)) {
                $table->text('two_factor_recovery_codes')->nullable();
            }

            if (! in_array('two_factor_confirmed_at', $columns, true)) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = Schema::getColumnListing('users');

        Schema::table('users', function (Blueprint $table) use ($columns) {
            foreach ([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ] as $column) {
                if (in_array($column, $columns, true)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
