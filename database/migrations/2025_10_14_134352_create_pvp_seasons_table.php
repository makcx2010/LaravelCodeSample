<?php

use App\Enums\PvpSeasonStatus;
use App\Models\PvpSeason;
use Carbon\Carbon;
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
        Schema::create('pvp_seasons', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('status');
            $table->timestamp('finish_at')->useCurrent();
        });
        Schema::create('user_pvp_rewards', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('pvp_season_id', 36);
            $table->string('user_id', 36);
            $table->string('status');
            $table->integer('crystals_amount');
            $table->foreign('pvp_season_id')->references('id')->on('pvp_seasons')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'pvp_season_id']);
        });

        DB::transaction(function () {
            $finishAt = Carbon::now()->startOfWeek();
            for($i = 0; $i < 500; $i++) {
                PvpSeason::create(['status' => PvpSeasonStatus::active->value, 'finish_at' => $finishAt->addWeek()]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_pvp_rewards');
        Schema::dropIfExists('pvp_seasons');
    }
};
