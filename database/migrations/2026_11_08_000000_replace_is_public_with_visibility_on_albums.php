<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public / unlisted / private replaces the is_public boolean. Fresh installs
 * already get `visibility` from create_albums_table, so this migration only
 * has work to do on databases created before that change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('albums', 'is_public')) {
            return;
        }

        Schema::table('albums', function (Blueprint $table) {
            $table->string('visibility', 20)->default('public')->after('title');
        });

        DB::table('albums')->where('is_public', true)->update(['visibility' => 'public']);
        DB::table('albums')->where('is_public', false)->update(['visibility' => 'private']);

        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('albums', 'visibility')) {
            return;
        }

        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('is_public')->default(true)->after('title');
        });

        // "unlisted" has no boolean equivalent; keep it reachable as public.
        DB::table('albums')->whereIn('visibility', ['public', 'unlisted'])->update(['is_public' => true]);
        DB::table('albums')->where('visibility', 'private')->update(['is_public' => false]);

        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
