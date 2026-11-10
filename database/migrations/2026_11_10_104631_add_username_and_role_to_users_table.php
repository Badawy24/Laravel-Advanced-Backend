<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('username', 30)->nullable()->after('id');
            $table->enum('role', ['customer', 'admin'])->default('customer')->after('password');
        });

        $users = DB::table('users')->whereNull('username')->get();
        foreach ($users as $user) {
            $base = $user->email ? explode('@', (string) $user->email)[0] : 'user'.$user->id;
            $username = strtolower((string) preg_replace('/[^a-z0-9_.]/', '', (string) $base));
            DB::table('users')->where('id', $user->id)->update([
                'username' => $username !== '' ? $username : 'user'.$user->id,
            ]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 30)->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'role']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
