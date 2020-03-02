<?php

$server_message_found = false;
$loser = '';
$losers = [];
$winners = [];
$player = [];
$player_id = '';
$team = [];
$teams = [];
$losing_team = '';
$factions = [];
$filenames = [];
$skipped = false;
$i = 1;

$dayNum = date('d');
$base_url = "http://replays.wesnoth.org/1.14/2020/03/$dayNum/";

$input = @file_get_contents($base_url) or die("Could not access file: $base_url");
$regexp = "<a href=\"([^\"]*)\">(.*)<\/a>";
preg_match_all("/$regexp/siU", $input, $matches, PREG_SET_ORDER) or die("Could not find any replay files!");

foreach($matches as $filename) {

    $info = pathinfo($filename[1]);
    if (isset($info['extension']) && $info['extension'] == 'bz2') {
        echo "Reading game $i for details...\n";

        // stop at 3 replay files.
        if ($i > 3) {
            exit;
        }
        $fh = bzopen($base_url . $filename[1], "r");
        while ($line = fgets($fh)) {

            //Skip games that don't use map settings
            if (strpos($line, 'mp_use_map_settings=no') !== FALSE) {
                echo "Skipping map that does not use map settings.\n";
                $skipped = true;
                break;
            }

            //Skip games that have AI players
            if (strpos($line, 'controller="ai"') !== FALSE) {
                echo "Skipping game with an AI player.\n";
                $skipped = true;
                break;
            }

            // Finished processing player data so clear the player.
            if (strpos($line, "[/side]") !== FALSE) {
                $player_id = '';
                continue;
            }

            if (preg_match('/current_player="(.*)"/', $line, $player)) {
                $player_id = $player[1];
                continue;
            }

            if ($player_id && preg_match('/team_name="(.*)"/', $line, $team)) {

                // Add the player to the teams
                $teams[$player_id] = $team[1];
                continue;
            }
            if ($player_id && preg_match('/faction="(.*)"/', $line, $faction)) {
                $factions[$player_id] = $faction[1];
            }

            // If the last line was a server message, process this one.
            if ($server_message_found) {

                // Check for rejoin and remove loser
                if (strpos($line, "$loser has joined") !== FALSE) {
                    $loser = '';
                }

                if (preg_match('/"(.*) has left the game/', $line, $player)) {
                    print($player[1] . " has left!\n");

                    // Are you the first person to leave?
                    if (!$loser) {
                        $loser = $player[1];
                    } else {

                        // Two people have now left. So, the game is over.
                        break;
                    }
                }
                if (preg_match('/"(.*) has surrendered/', $line, $player)) {
                    $loser = $player[1];

                    // Game over as soon as one player on a side surrenders
                    break;
                }

                // Reset server message flag
                $server_message_found = false;
            }

            // If it's a server message!
            if (strpos($line, 'id="server"') !== FALSE) {
                $server_message_found = true;
            }
        }

        // Output stats if not skipped.
        if (!$skipped) {
            if ($loser) {
                $losing_team = $teams[$loser];
                $losers[] = $loser;
            }

            foreach ($teams as $player => $team) {
                if ($team == $losing_team) {
                    $losers[] = $player;
                    echo "$player lost as $factions[$player]!\n";
                } else {
                    $winners[] = $player;
                    echo "$player as $factions[$player]!\n";
                }
            }
        }
        $i++;
        bzclose($fh);
    }
}