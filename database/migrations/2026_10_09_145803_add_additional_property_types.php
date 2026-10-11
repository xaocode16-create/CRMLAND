<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('property_types')->upsert([
            ['name' => 'Casa de Playa', 'slug' => 'casa-de-playa', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Duplex', 'slug' => 'duplex', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Otro', 'slug' => 'otro', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ], ['slug'], ['name', 'status', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('property_types')
            ->whereIn('slug', ['casa-de-playa', 'duplex', 'otro'])
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('properties')
                    ->whereColumn('properties.property_type_id', 'property_types.id');
            })
            ->delete();
    }
};
