<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When an instructor or admin last issued this student a sign-in PIN.
            $table->timestamp('pin_issued_at')->nullable()->after('password');
            // Staff created with the shared import default must pick their own password.
            $table->boolean('must_change_password')->default(false)->after('pin_issued_at');
        });

        // Accounts still on the old shared import default ('password123') are known to everyone.
        // Staff are asked to choose a new password at their next sign-in; students get a PIN from
        // their instructor (Course Offerings > Evaluators > Sign-in PINs).
        DB::table('users')->select(['id', 'password', 'is_student'])->whereNotNull('password')->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    if (Hash::check('password123', $user->password)) {
                        DB::table('users')->where('id', $user->id)->update(['must_change_password' => ! $user->is_student]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pin_issued_at', 'must_change_password']);
        });
    }
};
