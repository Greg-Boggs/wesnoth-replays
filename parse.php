<?php
define('DEFAULT_RATING', 1500);
define('WINS', 'wins');
define('LOSSES', 'losses');
define('GAMES_REQUIRED', 3);

global $con;

$server_message_found = false;
$loser = '';
$filenames = [];
$starting_gold = '';
$outcome = '';
$sides = [];
$game['default_settings'] = true;
$sides_count = 0;
$dayNum = date('d');
$base_url = "http://replays.wesnoth.org/1.14/2020/03/$dayNum/";
$game = init_game();
$ai_match = false;

$host = "localhost"; /* Host name */
$user = "oem"; /* User */
$password = "root"; /* Password */
$dbname = "replays"; /* Database name */

$con = mysqli_connect($host, $user, $password, $dbname) or die("Connection failed: " . mysqli_connect_error());


$input = @file_get_contents($base_url) or die("Could not access file: $base_url");
$regexp = "<a href=\"([^\"]*)\">(.*)<\/a>";
preg_match_all("/$regexp/siU", $input, $matches, PREG_SET_ORDER) or die("Could not find any replay files!");

foreach($matches as $filename) {

    $info = pathinfo($filename[1]);
    if (isset($info['extension']) && $info['extension'] == 'bz2') {
        $game['url'] = $base_url . $filename[1];
        $game['filename'] = $filename[1];

        echo "Reading game called $filename[1] for details...\n";

        if (preg_match('/_Turn_(.*)_/', $filename[1], $turn_matches)) {
            if ($turn_matches[1] < 4) {
                echo "Skipping game because it was shorter than 4 turns.\n";
                $game['default_settings'] = false;
                continue;
            } else {
                $game['turns'] = $turn_matches[1];
            }
        }

        $fh = bzopen($base_url . $filename[1], "r");
        while ($line = fgets($fh)) {

            if (preg_match('/mp_era="(.*)"/', $line, $matches)) {
                $game['era'] = $matches[1];
            }

            //Custom XP modifier
            if (preg_match('/experience_modifier="(.*)"/', $line, $matches)) {
                if ($matches[1] != '70') {
                    echo "Skipping game that uses custom experience.\n";
                    $game['default_settings'] = false;
                    break;
                }
            }

            //Possibly skip games that don't use map settings
            if (strpos($line, 'mp_use_map_settings=no') === true) {
                echo "Map that does not use map settings.\n";
                $game['use_map_settings'] = false;
            }

            if (!isset($game['use_map_settings']) && preg_match('/mp_village_gold="(.*)"/', $line, $matches)) {
                if ($matches[1] != '1') {
                    echo "Skipping map that uses custom gold.\n";
                    $game['default_settings'] = false;
                    break;
                }
            }

            if (!$game['use_map_settings'] && preg_match('/mp_village_support="(.*)"/', $line, $matches)) {
                if ($matches[1] != '2') {
                    echo "Skipping map that uses custom gold.\n";
                    $game['default_settings'] = false;
                    break;
                }
            }

            //Skip games that have AI players
            if (strpos($line, 'controller="ai"') !== false) {
                echo "Skipping game with an AI player.\n";
                $ai_match = true;
                break;
            }

            // Finished processing player data so clear the player.
            if (strpos($line, "[/side]") !== false) {
                $sides_count++;
                continue;
            }

            if (preg_match('/current_player="(.*)"/', $line, $player_match)) {
                $sides[$sides_count]['name'] = $player_match[1];
                continue;
            }

            if (isset($sides[$sides_count]['name']) && preg_match('/team_name="(.*)"/', $line, $team_match)) {

                // Add the player to the teams
                $sides[$sides_count]['team'] = $team_match[1];
                continue;
            }

            if (isset($sides[$sides_count]['name']) && preg_match('/faction="(.*)"/', $line, $faction)) {
                $sides[$sides_count]['faction'] = $faction[1];
            }

            if (preg_match('/^gold="(.*)"/', $line, $matches)) {
                if ($starting_gold) {
                    $old_gold = $starting_gold;
                    $starting_gold = $matches[1];
                    if ($old_gold != $starting_gold) {
                        echo "Skipping game with unequal gold of $old_gold vs $starting_gold.\n";
                        $game['default_settings'] = false;
                        break;
                    }
                } else {
                    $starting_gold = $matches[1];
                }
            }


            if (preg_match('/mp_scenario="multiplayer_(.*)"/', $line, $map_match)) {
                $game['map'] = $map_match['1'];
                if ($game['map'] == 'Isars_Cross') {
                    if ($starting_gold != '75') {
                        echo "Skipping game with non-default gold of $starting_gold.\n";
                        $game['default_settings'] = false;
                        break;
                    }
                } elseif ($starting_gold != '100') {
                    echo "Skipping game with non-default gold of $starting_gold.\n";
                    $game['default_settings'] = false;
                    break;
                }
            }

            // If the last line was a server message, process this one.
            if ($server_message_found) {

                $game['player_count'] = $sides_count;

                // Check for rejoin and remove loser
                if (strpos($line, "$loser has joined") !== false) {
                    $loser = '';
                }

                if (preg_match('/"(.*) has left the game/', $line, $player_match)) {
                    print($player_match[1] . " has left!\n");

                    // Are you the first person to leave?
                    if (!$loser) {
                        $loser = $player_match[1];
                    } else {

                        // Two people have now left. So, the game is over.
                        break;
                    }
                }
                if (preg_match('/"(.*) has surrendered/', $line, $player_match)) {
                    if (!$loser) {
                        $loser = $player_match[1];

                        // Game over as soon as one player on a side surrenders
                        break;
                    }
                }

                // Reset server message flag
                $server_message_found = false;
            }

            // If it's a server message!
            if (strpos($line, 'id="server"') !== false) {
                $server_message_found = true;
            }
        }

        bzclose($fh);

        // Output stats if not skipped.
        if (!$ai_match && $game['default_settings']) {

            $sides = get_outcome($sides, $loser);

            foreach ($sides as $key => $side) {
                $sides[$key]['player'] = create_or_load_player($side);
            }

            $sides = get_rating_change($sides);

            foreach (sides as $key => $side) {
                update_player($side);
            }

            // Create the game
            $game['game_id'] = create_game($game);

            $j = 0;
            foreach ($sides as $key => $side) {
                create_side($side, $game);
            }
            echo "Recoded game \n.";
        }

        //Reset the stats for the next game.
        $loser = '';
        $sides = [];
        $losing_team = '';
        $starting_gold = '';
        $old_gold = '';
        $game = init_game();
        $ai_match = false;
    }
}

$con->close();

function init_game() {

    return [
        'game_id' => null,
        'date' => date('Y-m-d'),
        'default_settings' => true,
        'use_map_settings' => true,
        'player_count' => '',
        'map' => '',
        'era' => '',
        'turns' => '',
        'filename' => '',
        'url' => '',
    ];
}
function get_rating_change($sides) {

    // Discard newbies.
    foreach ($sides as $side) {

    }
    //Calculate rating changes
    foreach ($sides as $key => $side) {
        if ($side['outcome'] == WINS)

            $sides[$key]['rating_change'] = 15;
        else {
            $sides[$key]['rating_change'] = -15;
        }
    }

    return $sides;
}

function get_k_factor ($games_played, $rating) {
    $k_factor = 40;

    if ($games_played > 30) {
        $k_factor = 20;
    }
    if ($rating > 2400) {
        $k_factor = 10;
    }

    return $k_factor;
}

function expectedScore($aRating, $bRating) {
    return 1 / (1 + pow(10, ($bRating - $aRating) / 200));
}

function updateValue($score, $expectedScore, $kFactor) {
    return $kFactor * ($score - $expectedScore);
}

function get_outcome($sides, $loser) {
    $losing_team = get_losing_team ($sides, $loser);

    foreach ($sides as $key => $side) {
        if ($side['team'] == $losing_team) {
            $sides[$key]['outcome'] = LOSSES;
        } else {
            $sides[$key]['outcome'] = WINS;
        }
    }

    return $sides;
}

function get_losing_team($sides, $loser) {
    $losing_team = '';

    foreach ($sides as $side) {
        if ($side['name'] == $loser) {
            $losing_team = $side['team'];
        }
    }

    return $losing_team;
}

function create_update_player($side)
{
    global $con;
    $insert = false;
    $row = [];

    $sql = "select * from players where name = '$side[name]'";
    $result = $con->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = mysqli_fetch_assoc($result);
        $new_outcome_total = $row[$side['outcome']] + 1;
        $new_games_total = $row['games'] + 1;
        $side['rating'] = $row['rating'] + $side['rating_change'];
        $sql = "UPDATE players "
            . "SET rating=$side[rating], $side[outcome]=$new_outcome_total, $new_games_total "
            . "WHERE id = $row[id]";
        $side['rating'] = $row['rating'];
        $side['games'] = $row['games'];
    } else {
        $side['rating'] = DEFAULT_RATING + $side['rating_change'];
        $sql = "INSERT INTO players (name, rating, $side[outcome]) "
            . "VALUES ('$side[name]', $side[rating], 1, 1) ";
        $insert = true;
        $side['games'] = 1;
    }
    if (mysqli_query($con, $sql) && $insert) {
        $side['player_id'] = $con->insert_id;
    } else {
        $side['player_id'] = $row['id'];
    }

    return $side;
}

function create_game($game) {
    global $con;

    // Insert game record
    $sql = "INSERT INTO games (date, default_settings, player_count, map, era, turns, filename, url) "
        . "VALUES ('$game[date]', $game[default_settings] , $game[player_count] , '$game[map]', '$game[era]', $game[turns], '$game[filename]', '$game[url]')";

    mysqli_query($con, $sql);

    return $con->insert_id;
}

function create_side($side, $game) {
    global $con;

    $sql = "INSERT INTO sides (outcome, faction, game_id, player_id) VALUES ('$side[outcome]', '$side[faction]', $game[game_id], $side[player_id])";

    mysqli_query($con, $sql);

    return $con->insert_id;
}