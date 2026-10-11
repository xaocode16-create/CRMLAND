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
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('code', 40)->nullable()->unique()->after('id');
            $table->string('operation_type', 20)->nullable()->after('property_id');
            $table->string('channel', 60)->nullable()->after('operation_type');
            $table->decimal('separation_amount', 15, 2)->default(0)->after('channel');
            $table->char('currency', 3)->default('PEN')->after('separation_amount');
            $table->foreignId('supervisor_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('branch', 120)->nullable()->after('supervisor_user_id');
            $table->string('priority', 20)->default('NORMAL')->after('branch');
            $table->boolean('requires_approval')->default(true)->after('priority');
            $table->string('approval_status', 20)->default('PENDING')->after('requires_approval');
            $table->foreignId('approved_by_user_id')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('approved_by_user_id');
            $table->text('reservation_reason')->nullable()->after('approved_at');
            $table->string('payment_method', 80)->nullable()->after('reservation_reason');
            $table->string('payment_bank', 120)->nullable()->after('payment_method');
            $table->string('payment_operation', 120)->nullable()->after('payment_bank');
            $table->dateTime('payment_date')->nullable()->after('payment_operation');
            $table->decimal('amount_paid', 15, 2)->default(0)->after('payment_date');
            $table->string('payment_status', 20)->default('PENDING')->after('amount_paid');
            $table->text('payment_observation')->nullable()->after('payment_status');
            $table->json('documents_json')->nullable()->after('payment_observation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropForeign(['supervisor_user_id']);
            $table->dropForeign(['approved_by_user_id']);
            $table->dropUnique(['code']);
            $table->dropColumn([
                'code',
                'operation_type',
                'channel',
                'separation_amount',
                'currency',
                'supervisor_user_id',
                'branch',
                'priority',
                'requires_approval',
                'approval_status',
                'approved_by_user_id',
                'approved_at',
                'reservation_reason',
                'payment_method',
                'payment_bank',
                'payment_operation',
                'payment_date',
                'amount_paid',
                'payment_status',
                'payment_observation',
                'documents_json',
            ]);
        });
    }
};
