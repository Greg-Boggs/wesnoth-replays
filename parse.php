<?php

$server_message_found = false;
$era = '';
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
$map = '';
$starting_gold = '';
$skipped = false;
$use_map_settings = true;
$i = 1;

$dayNum = date('d');
$base_url = "http://replays.wesnoth.org/1.14/2020/03/$dayNum/";

$input = @file_get_contents($base_url) or die("Could not access file: $base_url");
$regexp = "<a href=\"([^\"]*)\">(.*)<\/a>";
preg_match_all("/$regexp/siU", $input, $matches, PREG_SET_ORDER) or die("Could not find any replay files!");

foreach($matches as $filename) {

    $info = pathinfo($filename[1]);
    if (isset($info['extension']) && $info['extension'] == 'bz2') {

        // stop at 3 replay files.
        if ($i > 3) {
            exit;
        }

        echo "Reading game $i called $filename[1] for details...\n";

        if (preg_match('/_Turn_(.*)_/', $filename[1], $matches)) {
            if ($matches[1] < 4) {
                echo "Skipping game because it was shorter than 4 turns.\n";
                $skipped = true;
                continue;
            }
        }

        $fh = bzopen($base_url . $filename[1], "r");
        while ($line = fgets($fh)) {

            if (preg_match('/mp_era="(.*)"/', $line, $matches)) {
                $era = $matches[1];
            }

            //Custom XP modifier
            if (preg_match('/experience_modifier="(.*)"/', $line, $matches)) {
                if ($matches[1] != '70') {
                    echo "Skipping game that uses custom experience.\n";
                    $skipped = true;
                    break;
                }
            }

            //Possibly skip games that don't use map settings
            if (strpos($line, 'mp_use_map_settings=no') === true) {
                echo "Map that does not use map settings.\n";
                $use_map_settings = false;
            }

            if(!$use_map_settings && preg_match('/mp_village_gold="(.*)"/', $line, $matches)) {
                if ($matches[1] != '1') {
                    echo "Skipping map that uses custom gold.\n";
                    $skipped = true;
                    break;
                }
            }

            if(!$use_map_settings && preg_match('/mp_village_support="(.*)"/', $line, $matches)) {
                if ($matches[1] != '2') {
                    echo "Skipping map that uses custom gold.\n";
                    $skipped = true;
                    break;
                }
            }

            //Skip games that have AI players
            if (strpos($line, 'controller="ai"') !== false) {
                echo "Skipping game with an AI player.\n";
                $skipped = true;
                break;
            }

            // Finished processing player data so clear the player.
            if (strpos($line, "[/side]") !== false) {
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
            if(preg_match('/^gold="(.*)"/', $line, $matches)) {
                if ($starting_gold) {
                    $old_gold = $starting_gold;
                    $starting_gold = $matches[1];
                    if ($old_gold != $starting_gold) {
                        echo "Skipping game with unequal gold of $old_gold vs $starting_gold.\n";
                        $skipped = true;
                        break;
                    }
                } else {
                    $starting_gold = $matches[1];
                }
            }


            if(preg_match('/mp_scenario="multiplayer_(.*)"/', $line, $matches)) {
                $map = $matches[1];
                if ($map == 'Isars_Cross') {
                    if ($starting_gold != '75') {
                        echo "Skipping game with non-default gold of $starting_gold.\n";
                        $skipped = true;
                        break;
                    }
                } elseif ($starting_gold != '100') {
                    echo "Skipping game with non-default gold of $starting_gold.\n";
                    $skipped = true;
                    break;
                }
            }

            // If the last line was a server message, process this one.
            if ($server_message_found) {

                // Check for rejoin and remove loser
                if (strpos($line, "$loser has joined") !== false) {
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
            if (strpos($line, 'id="server"') !== false) {
                $server_message_found = true;
            }
        }

        // Output stats if not skipped.
        if (!$skipped) {
            $i++;
            if ($era == 'ladder_era') {
                echo "Ladder Match\n";
            } elseif ($era == 'default_era') {
                echo "Default Era\n";
            }
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
                    echo "$player won as $factions[$player]!\n";
                }
            }
        }

        //Reset the stats for the next game.
        $loser = '';
        $teams = [];
        $losers = [];
        $winners = [];
        $factions = [];
        $losing_team = '';
        $starting_gold = '';
        $old_gold = '';
        $skipped = false;
        bzclose($fh);
    }
}