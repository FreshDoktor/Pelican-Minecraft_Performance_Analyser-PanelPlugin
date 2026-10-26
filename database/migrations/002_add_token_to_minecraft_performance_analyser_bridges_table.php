<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minecraft_performance_analyser_bridges', function (Blueprint $table) {
            // The bridge's own token (token in mpa-bridge.yml), encrypted by NodeBridge. Without one the panel
            // doesn't ask the node; rows from before have none, the admin enters it once.
            $table->text('token')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('minecraft_performance_analyser_bridges', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
