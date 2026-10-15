<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One MySQL account per student on the separate student database server.
        Schema::create('student_db_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mysql_user', 32)->unique();
            $table->text('password');
            $table->timestamps();
        });

        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->string('database_name', 64)->nullable();
            $table->longText('database_export')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_answers', fn (Blueprint $table) => $table->dropColumn(['database_name', 'database_export']));
        Schema::dropIfExists('student_db_accounts');
    }
};
