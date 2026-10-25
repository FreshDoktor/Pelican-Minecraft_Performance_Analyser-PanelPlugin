<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minecraft_performance_analyser_bridges', function (Blueprint $table) {
            $table->increments('id');

            // A node has at most one bridge. Nodes without a row use <scheme>://<fqdn>:<default_port>.
            $table->unsignedInteger('node_id')->unique();
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();

            // Base address the panel reaches the bridge under, e.g. http://10.0.0.5:8090, without a path.
            $table->string('url');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_performance_analyser_bridges');
    }
};
