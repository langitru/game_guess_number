<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Game extends Model
{
    use HasFactory;

    const NUMBER_OF_ROUNDS = 5;
    protected $minNumber   = 1;
    protected $maxNumber   = 20;

    protected $fillable = [
        'name',
        'status',
        'current_round',
        'winner'
    ];

    const STATUS = [
        'connecting_players' => 2, // подключение игроков
        'game_started'       => 1, // игра начата
        'game_over'          => 0  // игра окончена
    ];

    const ROLES = [
        'player_1' => 1,
        'player_2' => 2,
        'none'     => 8
    ];

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function rounds(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Round::class);
    }

    private function newRound($round_id, $data, $player_1)
    {
        $player_1_data = [
            "user_id" => $player_1->id,
            "number" => $data['body']
        ];

        $round = $this->rounds()->create([
            'round_id'    => $round_id,
            'game_id'     => $this->id,
            'player_1'     => json_encode($player_1_data)
        ]);

        Log::channel('daily')->log('info', 'Game newRound()', [$round]);

        if ($data['body'] === 'x') {
            return $this->completeRound($round, self::ROLES['player_2']);
        }

        return $round;
    }

    private function updateRound($round, $data, $player_2)
    {
        $player_2_data = [
            "user_id" => $player_2->id,
            "number" => $data['body']
        ];

        $round->player_2 = json_encode($player_2_data);
        $round->save();

        Log::channel('daily')->log('info', 'Game updateRound()', [$round]);

        if ($data['body'] === 'x') {
            return $this->completeRound($round, self::ROLES['player_1']);
        }

        return $this->completeRound($round);
    }

    private function completeRound($round, $winner = false)
    {
        if ($winner && $winner === self::ROLES['player_1']) {
            $round->winner = self::ROLES['player_1'];
            $round->save();
        }

        if ($winner && $winner === self::ROLES['player_2']) {
            $round->winner = self::ROLES['player_2'];
            $round->save();
        }

        if (!$winner) {
            $round->guess_number = rand($this->minNumber, $this->maxNumber);
            $round->save();

            $number_player_1 = json_decode($round->player_1);
            $number_player_2 = json_decode($round->player_2);

            $diff_player_1 = abs($round->guess_number - intval($number_player_1->number));
            $diff_player_2 = abs($round->guess_number - intval($number_player_2->number));

            $none = abs($diff_player_1 - $diff_player_2);

            if ($none === 0) {
                $round->winner = self::ROLES['none'];
                $round->save();
            } else {
                $favorit = min($diff_player_1, $diff_player_2);

                switch ($favorit) {
                    case $diff_player_1:
                        $round->winner = self::ROLES['player_1'];
                        $round->save();
                        break;

                    case $diff_player_2:
                        $round->winner = self::ROLES['player_2'];
                        $round->save();
                        break;
                }
            }
        }

        if ($this->current_round === self::NUMBER_OF_ROUNDS) {
            // конец игры
            $this->gameOver();

            return $round;
        }

        $this->current_round++;
        $this->save();

        return $round;
    }

    private function gameOver()
    {
        $rounds = $this->rounds()->select('winner')->get()->pluck('winner')->toArray();

        $count_of_wins_player_1 = 0;
        $count_of_wins_player_2 = 0;

        foreach ($rounds as $user) {
            if ($user === self::ROLES['player_1']) {
                $count_of_wins_player_1++;
            }
            if ($user === self::ROLES['player_2']) {
                $count_of_wins_player_2++;
            }
        }

        if ($count_of_wins_player_1 > $count_of_wins_player_2) {
            $this->winner = self::ROLES['player_1'];
        }

        if ($count_of_wins_player_1 < $count_of_wins_player_2) {
            $this->winner = self::ROLES['player_2'];
        }

        if ($count_of_wins_player_1 === $count_of_wins_player_2) {
            $this->winner = self::ROLES['none'];
        }

        $this->status = self::STATUS['game_over'];
        $this->save();
        Log::channel('daily')->log('info', 'Game gameOver()', ['game_status' => $this->status]);
    }

    public function whoIsWinner()
    {
        if ($this->winner != self::ROLES['none']) {
            $player_winner = $this->users()->where('role', $this->winner)->first();
            Log::channel('daily')->log('info', 'Game whoIsWinner()', ['player_winner' => $player_winner]);

            return $player_winner;
        } else {
            return self::ROLES['none'];
        }
    }

    public function leaveGame($user)
    {
        $this->status = Game::STATUS['game_over'];
        $this->save();

        $player_1 = $this->users()->where('role', Game::ROLES['player_1'])->first();
        $player_2 = $this->users()->where('role', Game::ROLES['player_2'])->first();

        switch ($user->id) {
            case $player_1->id:
                $this->winner = self::ROLES['player_2'];
                $this->save();
                break;
            case $player_2->id:
                $this->winner = self::ROLES['player_1'];
                $this->save();
                break;
        }

        return $this->whoIsWinner();
    }

    public function processing($data)
    {
        if ($this->status === self::STATUS['game_over']) {
            Log::channel('daily')->log('info', 'Game processing() - status = game_over');

            return false;
        }

        Log::channel('daily')->log('info', 'Game processing()', [$data]);

        $player_1 = $this->users()->where('role', self::ROLES['player_1'])->first();
        $player_2 = $this->users()->where('role', self::ROLES['player_2'])->first();

        if ($player_1->id === $data['user_id']) {
            $player = $player_1;
        } else {
            $player = $player_2;
        }

        $round = $this->rounds()->where('round_id', $this->current_round)->first();

        if (!$round) {
            return $this->newRound($this->current_round, $data, $player);
        }

        return $this->updateRound($round, $data, $player);
    }
}
