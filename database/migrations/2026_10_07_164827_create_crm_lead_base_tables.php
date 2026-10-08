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
        if (! Schema::hasTable('lead_sources')) {
            Schema::create('lead_sources', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 80)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lead_statuses')) {
            Schema::create('lead_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 80)->unique();
                $table->string('color', 20)->nullable();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->string('first_name', 120);
                $table->string('last_name', 120)->nullable();
                $table->string('full_name');
                $table->string('phone', 30)->unique();
                $table->string('email')->nullable()->unique();
                $table->foreignId('source_id')->constrained('lead_sources')->restrictOnDelete();
                $table->foreignId('status_id')->constrained('lead_statuses')->restrictOnDelete();
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('campaign_id')->nullable();
                $table->string('district', 120)->nullable()->index();
                $table->string('property_type', 120)->nullable();
                $table->decimal('budget_min', 15, 2)->nullable();
                $table->decimal('budget_max', 15, 2)->nullable();
                $table->char('budget_currency', 3)->nullable()->default('PEN')->index();
                $table->text('notes')->nullable();
                $table->dateTime('entered_at')->index();
                $table->dateTime('last_contact_at')->nullable()->index();
                $table->dateTime('next_follow_up_at')->nullable()->index();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Adopted CRM lead tables can already contain production data; rollback must not drop them.
    }
};
