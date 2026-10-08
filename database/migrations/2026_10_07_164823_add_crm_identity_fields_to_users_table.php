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
            if (! in_array('username', $columns, true)) {
                $table->string('username', 80)->nullable()->unique();
            }

            if (! in_array('role_id', $columns, true)) {
                $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            }

            if (! in_array('supervisor_user_id', $columns, true)) {
                $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! in_array('phone', $columns, true)) {
                $table->string('phone', 30)->nullable();
            }

            if (! in_array('avatar', $columns, true)) {
                $table->string('avatar')->nullable();
            }

            if (! in_array('status', $columns, true)) {
                $table->string('status', 20)->default('active')->index();
            }

            if (! in_array('session_version', $columns, true)) {
                $table->unsignedInteger('session_version')->default(1);
            }

            if (! in_array('last_login_at', $columns, true)) {
                $table->dateTime('last_login_at')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // User identity columns are part of the canonical CRM user model.
    }
};
