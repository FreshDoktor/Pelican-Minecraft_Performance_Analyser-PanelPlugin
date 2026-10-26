<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minecraft_performance_analyser_bridges', function (Blueprint $table) {
            // Pin of the bridge's certificate (mpa-bridge -fingerprint, sha256//<base64>), not secret. With it the
            // panel checks the certificate by its key instead of the CA store; without it as before.
            $table->string('tls_pin')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('minecraft_performance_analyser_bridges', function (Blueprint $table) {
            $table->dropColumn('tls_pin');
        });
    }
};
