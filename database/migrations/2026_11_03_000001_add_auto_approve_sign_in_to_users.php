<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Staff can let a student without a PIN in without waiting for someone to approve the browser. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('auto_approve_sign_in')->default(false)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('auto_approve_sign_in');
        });
    }
};
