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
        if (! Schema::hasTable('lead_activities')) {
            Schema::create('lead_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 80)->index();
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('lead_notes')) {
            Schema::create('lead_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->text('content');
                $table->timestamps();
                $table->softDeletes();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('follow_ups')) {
            Schema::create('follow_ups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 30);
                $table->dateTime('scheduled_at')->index();
                $table->text('note')->nullable();
                $table->string('status', 30)->default('PENDING')->index();
                $table->string('priority', 30)->default('NORMAL')->index();
                $table->dateTime('completed_at')->nullable();
                $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['assigned_user_id', 'status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('pipeline_history')) {
            Schema::create('pipeline_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('from_stage_id')->nullable()->constrained('lead_statuses')->nullOnDelete();
                $table->foreignId('to_stage_id')->constrained('lead_statuses')->restrictOnDelete();
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('property_types')) {
            Schema::create('property_types', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 80)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->string('title', 180);
                $table->text('description')->nullable();
                $table->foreignId('property_type_id')->constrained('property_types')->restrictOnDelete();
                $table->string('operation_type', 20)->default('SALE')->index();
                $table->string('region', 120)->nullable();
                $table->string('province', 120)->nullable();
                $table->string('district', 120)->index();
                $table->string('address');
                $table->string('location_reference')->nullable();
                $table->string('building_name', 160)->nullable();
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->decimal('price', 15, 2)->default(0)->index();
                $table->char('currency', 3)->default('PEN');
                $table->decimal('area_m2', 10, 2)->default(0);
                $table->decimal('built_area_m2', 10, 2)->nullable();
                $table->unsignedInteger('bedrooms')->nullable();
                $table->unsignedInteger('bathrooms')->nullable();
                $table->unsignedInteger('parking_spaces')->nullable();
                $table->unsignedInteger('floors')->nullable();
                $table->unsignedInteger('floor_number')->nullable();
                $table->unsignedSmallInteger('year_built')->nullable();
                $table->string('developer', 180)->nullable();
                $table->string('project_status', 60)->nullable()->index();
                $table->string('project_type', 60)->nullable();
                $table->unsignedInteger('total_units')->nullable();
                $table->decimal('minimum_area', 10, 2)->nullable();
                $table->decimal('maximum_area', 10, 2)->nullable();
                $table->decimal('price_from', 15, 2)->nullable();
                $table->decimal('price_to', 15, 2)->nullable();
                $table->char('project_currency', 3)->nullable();
                $table->date('construction_start')->nullable();
                $table->date('delivery_date')->nullable();
                $table->string('financing', 120)->nullable();
                $table->json('attributes_json')->nullable();
                $table->string('status', 30)->default('AVAILABLE')->index();
                $table->string('owner_name', 160)->nullable();
                $table->string('owner_phone', 30)->nullable();
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('property_images')) {
            Schema::create('property_images', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->string('file_name');
                $table->string('storage_key', 500)->unique();
                $table->string('mime_type', 100);
                $table->unsignedBigInteger('file_size');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['property_id', 'is_primary', 'sort_order']);
            });
        }

        if (! Schema::hasTable('lead_properties')) {
            Schema::create('lead_properties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->string('relation_type', 30)->default('INTERESTED')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->unique(['lead_id', 'property_id']);
            });
        }

        if (! Schema::hasTable('property_units')) {
            Schema::create('property_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
                $table->string('unit_number', 80);
                $table->string('unit_type', 80)->nullable();
                $table->string('tower', 80)->nullable();
                $table->string('floor', 40)->nullable();
                $table->decimal('area_m2', 10, 2)->nullable();
                $table->decimal('built_area_m2', 10, 2)->nullable();
                $table->unsignedInteger('bedrooms')->nullable();
                $table->unsignedInteger('bathrooms')->nullable();
                $table->unsignedInteger('parking_spaces')->nullable();
                $table->decimal('price', 15, 2)->nullable();
                $table->char('currency', 3)->default('PEN');
                $table->string('status', 30)->default('AVAILABLE')->index();
                $table->json('attributes_json')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['property_id', 'unit_number']);
                $table->index(['property_id', 'status']);
            });
        }

        if (! Schema::hasTable('sales')) {
            Schema::create('sales', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
                $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
                $table->foreignId('advisor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
                $table->foreignId('pipeline_history_id')->nullable()->unique()->constrained('pipeline_history')->nullOnDelete();
                $table->decimal('price', 15, 2)->default(0);
                $table->char('currency', 3)->default('PEN');
                $table->dateTime('sold_at')->index();
                $table->string('origin', 30)->default('manual');
                $table->string('status', 30)->default('REGISTERED')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['lead_id', 'property_id', 'deleted_at']);
            });
        }

        if (! Schema::hasTable('commissions')) {
            Schema::create('commissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_id')->unique()->constrained('sales')->cascadeOnDelete();
                $table->foreignId('advisor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('percentage', 6, 3)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->char('currency', 3)->default('PEN');
                $table->string('status', 30)->default('PENDING')->index();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('approved_at')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reservations')) {
            Schema::create('reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
                $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->dateTime('reserved_at');
                $table->dateTime('expires_at')->index();
                $table->string('status', 30)->default('ACTIVE')->index();
                $table->string('idempotency_key', 190)->unique();
                $table->timestamps();
                $table->index(['property_id', 'status']);
                $table->index(['lead_id', 'status']);
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
                $table->string('type', 60);
                $table->string('title', 190);
                $table->text('body')->nullable();
                $table->string('status', 20)->default('UNREAD')->index();
                $table->timestamp('created_at')->nullable();
                $table->dateTime('read_at')->nullable();
                $table->index(['user_id', 'status', 'created_at']);
            });
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name', 160)->nullable();
                $table->string('action', 120)->index();
                $table->string('module', 80)->nullable()->index();
                $table->string('entity_type', 80);
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->text('description');
                $table->json('metadata')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('user_agent')->nullable();
                $table->string('request_id', 64)->nullable()->index();
                $table->timestamp('created_at')->nullable()->index();
                $table->index(['entity_type', 'entity_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Adopted CRM operational tables can contain live business data; rollback is intentionally non-destructive.
    }
};
