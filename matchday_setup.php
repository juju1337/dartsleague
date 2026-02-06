<?php
// matchday_setup.php - Tournament and Matchday Setup
session_start();

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Redirect non-admins
if (!$is_admin) {
    header('Location: index.php');
    exit;
}

$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';

// Initialize CSV files
if (!file_exists($matchdays_file)) {
    $fp = fopen($matchdays_file, 'w');
    fputcsv($fp, ['id', 'date', 'location']);
    fclose($fp);
}

if (!file_exists($matches_file)) {
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    fclose($fp);
}

// Load data
$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// Handle form submission
$setup_complete = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_tournament'])) {
        // Server-side validation: check selected players count
        $selected_count = 0;
        if (isset($_POST['selected_players']) && is_array($_POST['selected_players'])) {
            $selected_count = count($_POST['selected_players']);
        } else {
            // No player selection means all players
            $selected_count = count(loadPlayers());
        }
        
        if ($selected_count < 4) {
            $validation_error = "Cannot create tournament with fewer than 4 players. Selected: $selected_count player(s).";
        } else {
            generateTournament($_POST);
            $setup_complete = true;
        }
    }
    
    if (isset($_POST['add_matchday'])) {
        $result = addSingleMatchday($matchdays, $all_matches, $matchdays_file, $matches_file);
        if ($result['success']) {
            $add_success = $result['message'];
            // Reload data
            $matchdays = loadMatchdays();
            $all_matches = loadMatches();
        } else {
            $add_error = $result['message'];
        }
    }
}

// Functions
function loadPlayers() {
    global $players_file;
    $players = [];
    if (file_exists($players_file) && ($fp = fopen($players_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $players[] = ['id' => $row[0], 'name' => $row[1], 'nickname' => $row[2]];
        }
        fclose($fp);
    }
    return $players;
}

function loadMatchdays() {
    global $matchdays_file;
    $matchdays = [];
    if (file_exists($matchdays_file) && ($fp = fopen($matchdays_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matchdays[] = [
                'id' => $row[0],
                'date' => $row[1],
                'location' => $row[2],
                'standingsmethod' => isset($row[3]) ? $row[3] : 'points',
                'winpoints' => isset($row[4]) ? intval($row[4]) : 2
            ];
        }
        fclose($fp);
    }
    return $matchdays;
}

function generateTournament($config) {
    global $matchdays_file, $matches_file;
    
    // Clear existing tournament data
    if (file_exists($matchdays_file)) {
        $fp = fopen($matchdays_file, 'w');
        fputcsv($fp, ['id', 'date', 'location']);
        fclose($fp);
    }
    
    // Save scoring scheme
    $scoring_file = 'tables/scoringscheme.csv';
    $fp_score = fopen($scoring_file, 'w');
    fputcsv($fp_score, ['stat', 'rank', 'points']);
    
    // Group phase positions
    fputcsv($fp_score, ['pos_group_phase', '1', $config['score_group_1']]);
    fputcsv($fp_score, ['pos_group_phase', '2', $config['score_group_2']]);
    fputcsv($fp_score, ['pos_group_phase', '3', $config['score_group_3']]);
    fputcsv($fp_score, ['pos_group_phase', '4', $config['score_group_4']]);
    
    // Final positions
    fputcsv($fp_score, ['pos_final', '1', $config['score_final_1']]);
    fputcsv($fp_score, ['pos_final', '2', $config['score_final_2']]);
    fputcsv($fp_score, ['pos_final', '3', $config['score_final_3']]);
    fputcsv($fp_score, ['pos_final', '4', $config['score_final_4']]);
    
    // Best statistics
    fputcsv($fp_score, ['best_3da', '1', $config['score_best_3da']]);
    fputcsv($fp_score, ['best_dbl', '1', $config['score_best_dbl']]);
    fputcsv($fp_score, ['best_hs', '1', $config['score_best_hs']]);
    fputcsv($fp_score, ['best_hco', '1', $config['score_best_hco']]);
    fputcsv($fp_score, ['best_leg', '1', isset($config['score_best_leg']) ? $config['score_best_leg'] : 0]);
    fputcsv($fp_score, ['most_180s', '1', isset($config['score_most_180s']) ? $config['score_most_180s'] : 0]);
    fputcsv($fp_score, ['most_140s', '1', isset($config['score_most_140s']) ? $config['score_most_140s'] : 0]);
    fputcsv($fp_score, ['most_100s', '1', isset($config['score_most_100s']) ? $config['score_most_100s'] : 0]);
    
    fclose($fp_score);
    
    $all_players = loadPlayers();
    
    if (file_exists($matches_file)) {
        $fp = fopen($matches_file, 'w');
        fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
        fclose($fp);
    }
    
    $sets_file = 'tables/sets.csv';
    if (file_exists($sets_file)) {
        $fp = fopen($sets_file, 'w');
        fputcsv($fp, ['id', 'matchid', 'player1id', 'player2id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2', 'bestleg1', 'bestleg2', '180s1', '180s2', '140s1', '140s2', '100s1', '100s2']);
        fclose($fp);
    }
    
    $all_players = loadPlayers();
    
    // Filter selected players
    $players = [];
    if (isset($config['selected_players']) && is_array($config['selected_players'])) {
        foreach ($all_players as $player) {
            if (in_array($player['id'], $config['selected_players'])) {
                $players[] = $player;
            }
        }
    } else {
        $players = $all_players;
    }
    
    $num_regular_matchdays = intval($config['num_matchdays']);
    $has_special = isset($config['has_special']) && $config['has_special'] == '1';
    
    // Create matchdays
    $fp = fopen($matchdays_file, 'w');
    fputcsv($fp, ['id', 'date', 'location', 'standingsmethod', 'winpoints']);
    $total_matchdays = $has_special ? $num_regular_matchdays + 1 : $num_regular_matchdays;
    
    // Regular matchdays
    for ($i = 1; $i <= $num_regular_matchdays; $i++) {
        $standingsmethod = isset($config['regular_standingsmethod']) ? $config['regular_standingsmethod'] : 'points';
        $winpoints = isset($config['regular_winpoints']) ? intval($config['regular_winpoints']) : 2;
        fputcsv($fp, [$i, '', '', $standingsmethod, $winpoints]);
    }
    
    // Special matchday (if exists)
    if ($has_special) {
        $standingsmethod = isset($config['special_standingsmethod']) ? $config['special_standingsmethod'] : 'points';
        $winpoints = isset($config['special_winpoints']) ? intval($config['special_winpoints']) : 2;
        fputcsv($fp, [$num_regular_matchdays + 1, '', '', $standingsmethod, $winpoints]);
    }
    
    fclose($fp);
    
    // Create matches
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    $match_id = 1;
    
// Regular matchdays
    for ($day = 1; $day <= $num_regular_matchdays; $day++) {
        $match_id = generateMatchdayMatches(
            $fp, 
            $match_id, 
            $day, 
            $players,
            $config['regular_group_rounds'],
            $config['regular_group_sets'],
            $config['regular_group_legs'],
            isset($config['regular_has_playoffs']) && $config['regular_has_playoffs'] == '1',
            $config['regular_playoff_sets'],
            $config['regular_playoff_legs'],
            isset($config['regular_has_third']) && $config['regular_has_third'] == '1',
            isset($config['regular_different_final']) && $config['regular_different_final'] == '1',
            isset($config['regular_final_sets']) ? $config['regular_final_sets'] : $config['regular_playoff_sets'],
            isset($config['regular_final_legs']) ? $config['regular_final_legs'] : $config['regular_playoff_legs']
        );
    }
    
    // Special matchday
    if ($has_special) {
        $special_day = $num_regular_matchdays + 1;
        $match_id = generateMatchdayMatches(
            $fp,
            $match_id,
            $special_day,
            $players,
            $config['special_group_rounds'],
            $config['special_group_sets'],
            $config['special_group_legs'],
            isset($config['special_has_playoffs']) && $config['special_has_playoffs'] == '1',
            $config['special_playoff_sets'],
            $config['special_playoff_legs'],
            isset($config['special_has_third']) && $config['special_has_third'] == '1',
            isset($config['special_different_final']) && $config['special_different_final'] == '1',
            isset($config['special_final_sets']) ? $config['special_final_sets'] : $config['special_playoff_sets'],
            isset($config['special_final_legs']) ? $config['special_final_legs'] : $config['special_playoff_legs']
        );
    }
    
    fclose($fp);
}

function generateMatchdayMatches($fp, $match_id, $day, $players, $group_rounds, $group_sets, $group_legs, $has_playoffs, $playoff_sets, $playoff_legs, $has_third, $different_final, $final_sets, $final_legs) {
    
    // Generate group phase matches
    $all_group_matches = [];
    
    for ($round = 1; $round <= intval($group_rounds); $round++) {
        for ($i = 0; $i < count($players); $i++) {
            for ($j = $i + 1; $j < count($players); $j++) {
                $all_group_matches[] = [
                    'player1' => $players[$i]['id'],
                    'player2' => $players[$j]['id']
                ];
            }
        }
    }
    
    // Reorder matches to ensure no player plays more than twice in a row
    $ordered_matches = [];
    $player_consecutive_count = [];
    
    while (!empty($all_group_matches)) {
        $placed = false;
        $best_match = null;
        $best_key = null;
        $best_score = -1;
        
        foreach ($all_group_matches as $key => $match) {
            $p1 = $match['player1'];
            $p2 = $match['player2'];
            
            // Get consecutive play count for both players
            $p1_consecutive = isset($player_consecutive_count[$p1]) ? $player_consecutive_count[$p1] : 0;
            $p2_consecutive = isset($player_consecutive_count[$p2]) ? $player_consecutive_count[$p2] : 0;
            
            // Skip if either player has already played twice consecutively
            if ($p1_consecutive >= 2 || $p2_consecutive >= 2) {
                continue;
            }
            
            // Score this match (prefer players who haven't played recently)
            $score = (10 - $p1_consecutive) + (10 - $p2_consecutive);
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $match;
                $best_key = $key;
            }
        }
        
        // If we found a valid match, place it
        if ($best_match !== null) {
            $ordered_matches[] = $best_match;
            
            // Update consecutive counts for ALL players
            $new_consecutive_count = [];
            foreach ($players as $player) {
                $pid = $player['id'];
                if ($pid == $best_match['player1'] || $pid == $best_match['player2']) {
                    // Players in this match: increment their count
                    $new_consecutive_count[$pid] = (isset($player_consecutive_count[$pid]) ? $player_consecutive_count[$pid] : 0) + 1;
                } else {
                    // Players NOT in this match: reset to 0
                    $new_consecutive_count[$pid] = 0;
                }
            }
            $player_consecutive_count = $new_consecutive_count;
            
            unset($all_group_matches[$best_key]);
            $all_group_matches = array_values($all_group_matches); // Re-index
            $placed = true;
        } else {
            // No valid match found - reset all counters and try again
            $player_consecutive_count = [];
            
            // Take first available match
            if (!empty($all_group_matches)) {
                $match = array_shift($all_group_matches);
                $ordered_matches[] = $match;
                foreach ($players as $player) {
                    $pid = $player['id'];
                    if ($pid == $match['player1'] || $pid == $match['player2']) {
                        $player_consecutive_count[$pid] = 1;
                    } else {
                        $player_consecutive_count[$pid] = 0;
                    }
                }
            }
        }
    }
    
    // Write ordered matches to CSV
    foreach ($ordered_matches as $match) {
        fputcsv($fp, [
            $match_id++,
            $day,
            'group',
            $group_sets,
            $group_legs,
            $match['player1'],
            $match['player2'],
            0,
            0
        ]);
    }
    
    // Generate playoff matches if enabled
    if ($has_playoffs == '1') {
        // Semi-finals: 1st vs 4th, 2nd vs 3rd
        fputcsv($fp, [$match_id++, $day, 'semi1', $playoff_sets, $playoff_legs, 0, 0, 0, 0]);
        fputcsv($fp, [$match_id++, $day, 'semi2', $playoff_sets, $playoff_legs, 0, 0, 0, 0]);
        
        // 3rd place match if enabled
        if ($has_third) {
            fputcsv($fp, [$match_id++, $day, 'third', $playoff_sets, $playoff_legs, 0, 0, 0, 0]);
        }
        
        // Final - use different format if specified
        if ($different_final == '1') {
            fputcsv($fp, [$match_id++, $day, 'final', $final_sets, $final_legs, 0, 0, 0, 0]);
        } else {
            fputcsv($fp, [$match_id++, $day, 'final', $playoff_sets, $playoff_legs, 0, 0, 0, 0]);
        }
    }
    
    return $match_id;
    
    // Generate playoff matches if enabled
    if ($has_playoffs == '1') {
        // Semi-finals: 1st vs 4th, 2nd vs 3rd
        fputcsv($fp, [$match_id++, $day, 'semi1', $playoff_sets, $playoff_legs, 0, 0, 0, 0]); // 1st vs 4th
        fputcsv($fp, [$match_id++, $day, 'semi2', $playoff_sets, $playoff_legs, 0, 0, 0, 0]); // 2nd vs 3rd
        
        // 3rd place match if enabled
        if ($has_third) {
            fputcsv($fp, [$match_id++, $day, 'third', $playoff_sets, $playoff_legs, 0, 0, 0, 0]);
        }
        
        // Final
        fputcsv($fp, [$match_id++, $day, 'final', $playoff_sets, $playoff_legs, 0, 0, 0, 0]);
    }
    
    return $match_id;
}

function getPlayerName($player_id) {
    $all_players = loadPlayers();
    if ($player_id == 0) return 'TBD';
    foreach ($all_players as $p) {
        if ($p['id'] == $player_id) {
            return $p['nickname'] ? $p['name'] . ' (' . $p['nickname'] . ')' : $p['name'];
        }
    }
    return 'Unknown';
}

function canAddMatchday($matchdays, $all_matches) {
    if (empty($matchdays)) return false;
    
    // Check if special matchday exists
    // Assumption: if matchday count doesn't match expected pattern, there's a special one
    // Better approach: check if all matchdays have identical format
    
    $formats = [];
    foreach ($matchdays as $md) {
        $md_id = $md['id'];
        $md_matches = array_filter($all_matches, function($m) use ($md_id) {
            return $m['matchdayid'] == $md_id;
        });
        
        $group_matches = array_filter($md_matches, function($m) { return $m['phase'] == 'group'; });
        $playoff_matches = array_filter($md_matches, function($m) { return $m['phase'] != 'group'; });
        
        if (empty($group_matches)) continue;
        
        $first_group = array_values($group_matches)[0];
        
        $format_key = $first_group['firsttosets'] . '_' . $first_group['firsttolegs'] . '_' . count($group_matches);
        
        if (!empty($playoff_matches)) {
            $first_playoff = array_values($playoff_matches)[0];
            $format_key .= '_p_' . $first_playoff['firsttosets'] . '_' . $first_playoff['firsttolegs'] . '_' . count($playoff_matches);
        }
        
        $formats[$format_key] = isset($formats[$format_key]) ? $formats[$format_key] + 1 : 1;
    }
    
    // If more than one format exists, don't allow adding (indicates special matchday)
    if (count($formats) > 1) {
        return false;
    }
    
    return true;
}

function getMatchdayFormat($matchday_id, $all_matches) {
    // Extract complete format from an existing matchday
    $md_matches = array_filter($all_matches, function($m) use ($matchday_id) {
        return $m['matchdayid'] == $matchday_id;
    });
    
    $group_matches = array_filter($md_matches, function($m) { return $m['phase'] == 'group'; });
    $playoff_matches = array_filter($md_matches, function($m) { return $m['phase'] != 'group'; });
    
    if (empty($group_matches)) return null;
    
    $first_group = array_values($group_matches)[0];
    
    // Extract players from group matches
    $players = [];
    foreach ($group_matches as $match) {
        if (!in_array($match['player1id'], $players)) {
            $players[] = $match['player1id'];
        }
        if (!in_array($match['player2id'], $players)) {
            $players[] = $match['player2id'];
        }
    }
    
    // Calculate number of rounds
    $num_players = count($players);
    $matches_per_round = ($num_players * ($num_players - 1)) / 2;
    $group_rounds = $matches_per_round > 0 ? count($group_matches) / $matches_per_round : 1;
    
    $format = [
        'players' => $players,
        'group_rounds' => $group_rounds,
        'group_sets' => $first_group['firsttosets'],
        'group_legs' => $first_group['firsttolegs'],
        'has_playoffs' => !empty($playoff_matches) ? '1' : '0',
        'playoff_sets' => 0,
        'playoff_legs' => 0,
        'has_third' => false,
        'different_final' => false,
        'final_sets' => 0,
        'final_legs' => 0
    ];
    
    if (!empty($playoff_matches)) {
        $first_playoff = array_values($playoff_matches)[0];
        $format['playoff_sets'] = $first_playoff['firsttosets'];
        $format['playoff_legs'] = $first_playoff['firsttolegs'];
        
        // Check for third place match
        foreach ($playoff_matches as $pm) {
            if ($pm['phase'] == 'third') {
                $format['has_third'] = true;
            }
            if ($pm['phase'] == 'final') {
                // Check if final has different format
                if ($pm['firsttosets'] != $format['playoff_sets'] || $pm['firsttolegs'] != $format['playoff_legs']) {
                    $format['different_final'] = true;
                    $format['final_sets'] = $pm['firsttosets'];
                    $format['final_legs'] = $pm['firsttolegs'];
                }
            }
        }
    }
    
    return $format;
}

function addSingleMatchday($matchdays, $all_matches, $matchdays_file, $matches_file) {
    // Get format from first matchday
    $format = getMatchdayFormat(1, $all_matches);
    
    if (!$format) {
        return ['success' => false, 'message' => 'Could not determine matchday format.'];
    }
    
    // Get next matchday ID
    $next_matchday_id = count($matchdays) + 1;
    
    // Get standings configuration from first matchday
    $first_md = $matchdays[0];
    $standingsmethod = isset($first_md['standingsmethod']) ? $first_md['standingsmethod'] : 'points';
    $winpoints = isset($first_md['winpoints']) ? $first_md['winpoints'] : 2;
    
    // Find max match ID
    $max_match_id = 0;
    foreach ($all_matches as $m) {
        if ($m['id'] > $max_match_id) {
            $max_match_id = $m['id'];
        }
    }
    $next_match_id = $max_match_id + 1;
    
    // Append new matchday to matchdays.csv
    $fp = fopen($matchdays_file, 'a');
    fputcsv($fp, [$next_matchday_id, '', '', $standingsmethod, $winpoints]);
    fclose($fp);
    
    // Append new matches to matches.csv
    $fp = fopen($matches_file, 'a');
    
    // Get full player objects for generateMatchdayMatches
    $all_players = loadPlayers();
    $selected_players = [];
    foreach ($all_players as $player) {
        if (in_array($player['id'], $format['players'])) {
            $selected_players[] = $player;
        }
    }
    
    // Generate matches using existing function
    generateMatchdayMatches(
        $fp,
        $next_match_id,
        $next_matchday_id,
        $selected_players,
        $format['group_rounds'],
        $format['group_sets'],
        $format['group_legs'],
        $format['has_playoffs'],
        $format['playoff_sets'],
        $format['playoff_legs'],
        $format['has_third'],
        $format['different_final'],
        $format['final_sets'],
        $format['final_legs']
    );
    
    fclose($fp);
    
    return ['success' => true, 'message' => "Matchday $next_matchday_id added successfully."];
}

function loadMatches() {
    $matches_file = 'tables/matches.csv';
    $matches = [];
    if (file_exists($matches_file) && ($fp = fopen($matches_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matches[] = [
                'id' => $row[0],
                'matchdayid' => $row[1],
                'phase' => $row[2],
                'firsttosets' => $row[3],
                'firsttolegs' => $row[4],
                'player1id' => $row[5],
                'player2id' => $row[6],
                'sets1' => $row[7],
                'sets2' => $row[8]
            ];
        }
        fclose($fp);
    }
    return $matches;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Tournament Setup</title>
    <link rel="stylesheet" href="styles.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        function togglePlayoffs(prefix) {
            var checkbox = document.getElementById(prefix + '_has_playoffs');
            var settings = document.getElementById(prefix + '_playoff_settings');
            
            if (checkbox.checked) {
                settings.style.display = 'block';
            } else {
                settings.style.display = 'none';
            }
        
            // Also toggle playoff scoring section for regular matchdays
            if (prefix === 'regular') {
                var scoringSection = document.getElementById('regular_playoff_scoring');
                if (scoringSection) {
                    if (checkbox.checked) {
                        scoringSection.style.display = 'block';
                    } else {
                        scoringSection.style.display = 'none';
                    }
                }
            }
        }
        
        function toggleSpecial() {
            var checkbox = document.getElementById('has_special');
            var specialSettings = document.getElementById('special_settings');
            specialSettings.style.display = checkbox.checked ? 'block' : 'none';
        }
        function toggleFinalFormat(prefix) {
            var checkbox = document.getElementById(prefix + '_different_final');
            var finalSettings = document.getElementById(prefix + '_final_settings');
            finalSettings.style.display = checkbox.checked ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <?php if (isset($validation_error)): ?>
        <div class="warning" style="margin: 20px; padding: 15px; background-color: #fff3cd; border: 2px solid #ff0000;">
            <strong>Error:</strong> <?php echo $validation_error; ?>
        </div>
    <?php endif; ?>
    <nav>
        <a href="index.php">Tournament Overview</a>
        <?php if (!empty($matchdays)): ?>
            <?php foreach ($matchdays as $md): ?>
                | <a href="matchdays.php?view=<?php echo $md['id']; ?>">Matchday <?php echo $md['id']; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    
    <h1>Tournament Setup</h1>

    <?php if ($setup_complete): ?>
        <div class="info">
            <strong>Setup Complete!</strong> Tournament structure has been created.<br>
            <a href="matchdays.php"><button type="button">Go to Matchday Management</button></a>
            <a href="matchday_setup.php"><button type="button">Start New Setup</button></a>
        </div>
    <?php else: ?>
        <?php if (empty($players)): ?>
            <div class="warning">
                <strong>Warning:</strong> No players found! Please add players before setting up the tournament.<br>
                <a href="players.php"><button type="button">Go to Player Management</button></a>
            </div>
        <?php elseif (count($players) < 4): ?>
            <div class="warning">
                <strong>Error:</strong> You need at least 4 players to create a tournament.<br>
                Currently registered: <?php echo count($players); ?> player(s).<br><br>
                Tournaments require a minimum of 4 players for the playoff system (semi-finals: 1st vs 4th, 2nd vs 3rd).<br><br>
                <a href="players.php"><button type="button">Add More Players</button></a>
            </div>
        <?php else: ?>
                        <?php 
                // Check if tournament already exists
                $tournament_exists = !empty($matchdays);
                $can_add = $tournament_exists && canAddMatchday($matchdays, $all_matches);
            ?>
            
            <?php if (isset($add_success)): ?>
                <div class="info" style="margin: 20px; padding: 15px; background-color: #d4edda; border: 2px solid #28a745;">
                    <strong>Success:</strong> <?php echo $add_success; ?><br><br>
                    <a href="matchdays.php"><button type="button">View Matchdays</button></a>
                </div>
            <?php endif; ?>
            
            <?php if (isset($add_error)): ?>
                <div class="warning" style="margin: 20px; padding: 15px;">
                    <strong>Error:</strong> <?php echo $add_error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($can_add): ?>
                <div id="add_matchday_section" class="section" style="margin-bottom: 30px;">
                    <h2>Add New Matchday</h2>
                    
                    <?php
                    // Get format info to display
                    $format = getMatchdayFormat(1, $all_matches);
                    $num_players = count($format['players']);
                    $player_names = [];
                    foreach ($format['players'] as $pid) {
                        foreach ($players as $p) {
                            if ($p['id'] == $pid) {
                                $player_names[] = $p['name'];
                                break;
                            }
                        }
                    }
                    ?>
                    
                    <div class="info">
                        <p><strong>Add matchday <?php echo count($matchdays) + 1; ?> with the same format as existing matchdays:</strong></p>
                        <ul>
                            <li><strong>Players:</strong> <?php echo implode(', ', $player_names); ?> (<?php echo $num_players; ?> players)</li>
                            <li><strong>Group Phase:</strong> <?php echo $format['group_rounds']; ?> round(s), Best of <?php echo $format['group_sets']; ?> sets, First to <?php echo $format['group_legs']; ?> legs</li>
                            <?php if ($format['has_playoffs'] == '1'): ?>
                                <li><strong>Playoffs:</strong> Semi-Finals (Best of <?php echo $format['playoff_sets']; ?> sets, First to <?php echo $format['playoff_legs']; ?> legs)</li>
                                <?php if ($format['has_third']): ?>
                                    <li><strong>3rd Place Match:</strong> Yes</li>
                                <?php endif; ?>
                                <?php if ($format['different_final']): ?>
                                    <li><strong>Final:</strong> Best of <?php echo $format['final_sets']; ?> sets, First to <?php echo $format['final_legs']; ?> legs</li>
                                <?php else: ?>
                                    <li><strong>Final:</strong> Same format as Semi-Finals</li>
                                <?php endif; ?>
                            <?php else: ?>
                                <li><strong>Playoffs:</strong> No playoffs</li>
                            <?php endif; ?>
                            <li><strong>Standings Method:</strong> 
                                <?php 
                                $first_md = $matchdays[0];
                                if (isset($first_md['standingsmethod']) && $first_md['standingsmethod'] == 'leg_diff') {
                                    echo 'Leg Difference';
                                } else {
                                    echo 'Points-based (' . (isset($first_md['winpoints']) ? $first_md['winpoints'] : 2) . ' points per win)';
                                }
                                ?>
                            </li>
                        </ul>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('Add matchday <?php echo count($matchdays) + 1; ?> with the format shown above?');">
                        <input type="submit" name="add_matchday" value="Add Matchday <?php echo count($matchdays) + 1; ?>" style="background-color: #28a745; color: white; padding: 10px 20px; font-size: 16px;">
                    </form>
                </div>
                
                <hr style="margin: 30px 0;">
            <?php endif; ?>
            
            <?php if ($tournament_exists && !$can_add): ?>
                <div class="warning" style="margin-bottom: 30px;">
                    <strong>Cannot add matchdays:</strong> Your tournament has matchdays with different formats (possibly a special matchday).<br>
                    Adding matchdays is only allowed when all existing matchdays have identical format.<br><br>
                    <strong>Option:</strong> You can rebuild the entire tournament below.
                </div>
            <?php endif; ?>
            
            <?php if ($tournament_exists): ?>
                <div class="warning">
                    <strong>Warning:</strong> A tournament structure already exists with <?php echo count($matchdays); ?> matchdays.<br>
                    Creating a new tournament will overwrite all existing matchdays and matches.<br><br>
                    <strong>Options:</strong><br>
                    <a href="matchdays.php"><button type="button">Edit Existing Tournament</button></a>
                    <button type="button" onclick="showSetupForm()">Create New Tournament (Overwrite)</button>

                </div>
                <div id="setup_form" style="display: none;">
            <?php else: ?>
                <div id="setup_form">
            <?php endif; ?>
            
            <div class="info">
                <strong>Players registered:</strong> <?php echo count($players); ?>
            </div>
        <?php endif; ?>
        
        <?php if (count($players) >= 4): ?>
        <form method="POST">
            <div class="form-section">
                <h2>Select Participating Players</h2>
                <p>Select which players will participate in this tournament:</p>
                <?php foreach ($players as $player): ?>
                    <label class="inline">
                        <input type="checkbox" name="selected_players[]" value="<?php echo $player['id']; ?>" checked>
                        <?php echo getPlayerName($player['id']); ?>
                    </label><br>
                <?php endforeach; ?>
            </div>
            
            <div class="form-section">
                <h2>Regular Matchdays</h2>
                
                <label>Number of regular matchdays:</label>
                <input type="number" name="num_matchdays" min="1" value="6" required>
                
                <div class="subsection">
                    <h3>Group Phase Settings</h3>
                    
                    <label>Number of rounds (everyone plays everyone X times):</label>
                    <select name="regular_group_rounds" required>
                        <option value="1">1 (single round-robin)</option>
                        <option value="2">2 (double round-robin)</option>
                    </select>
                    
                    <label>Group match format - First to X sets:</label>
                    <input type="number" name="regular_group_sets" min="1" value="1" required>
                    
                    <label>Each set - First to X legs:</label>
                    <input type="number" name="regular_group_legs" min="1" value="3" required>
                    
                    <h3>Group Standings Configuration</h3>
                    <table>
                        <tr>
                            <th>Setting</th>
                            <th>Value</th>
                        </tr>
                        <tr>
                            <td>Standings Method</td>
                            <td>
                                <select name="regular_standingsmethod" id="regular_standingsmethod" onchange="toggleWinPoints('regular')">
                                    <option value="points" selected>Points-based (Win/Loss)</option>
                                    <option value="leg_diff">Leg Difference Only</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="regular_winpoints_row">
                            <td>Points for Win</td>
                            <td>
                                <input type="number" name="regular_winpoints" value="2" min="0" max="10" required style="width: 60px;">
                                <small>(Traditional: 2 points, Modern: 3 points)</small>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="subsection">
                    <h3>Playoff Settings</h3>
                    
                                        <label class="inline">
                        <input type="checkbox" id="regular_has_playoffs" name="regular_has_playoffs" value="1" onchange="togglePlayoffs('regular')" checked>
                        Include Top 4 Playoffs (semi-finals + final)
                    </label>
                    
                    <div id="regular_playoff_settings" style="display: block; margin-top: 15px;">
                        <label>Playoff match format - First to X sets:</label>
                        <input type="number" name="regular_playoff_sets" min="1" value="1" required>
                        
                        <label>Each set - First to X legs:</label>
                        <input type="number" name="regular_playoff_legs" min="1" value="5" required>
                        
                        <label class="inline">
                            <input type="checkbox" name="regular_has_third" value="1" checked>
                            Include 3rd place match
                        </label>
                        
                        <br><br>
                        <label class="inline">
                            <input type="checkbox" id="regular_different_final" name="regular_different_final" value="1" onchange="toggleFinalFormat('regular')">
                            Different format for final match
                        </label>
                        
                        <div id="regular_final_settings" style="display: none; margin-top: 15px; padding-left: 20px;">
                            <label>Final match format - First to X sets:</label>
                            <input type="number" name="regular_final_sets" min="1" value="1">
                            
                            <label>Each set - First to X legs:</label>
                            <input type="number" name="regular_final_legs" min="1" value="5">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h2>Special Final Matchday (Optional)</h2>
                
                <label class="inline">
                    <input type="checkbox" id="has_special" name="has_special" value="1" onchange="toggleSpecial()">
                    Add one special matchday with different format
                </label>
                
                <div id="special_settings" style="display: none; margin-top: 15px;">
                    <div class="subsection">
                        <h3>Group Phase Settings</h3>
                        
                        <label>Number of rounds:</label>
                        <select name="special_group_rounds">
                            <option value="1">1 (single round-robin)</option>
                            <option value="2">2 (double round-robin)</option>
                        </select>
                        
                        <label>Group match format - First to X sets:</label>
                        <input type="number" name="special_group_sets" min="1" value="3">
                        
                        <label>Each set - First to X legs:</label>
                        <input type="number" name="special_group_legs" min="1" value="5">
                        
                        <h3>Group Standings Configuration</h3>
                        <table>
                            <tr>
                                <th>Setting</th>
                                <th>Value</th>
                            </tr>
                            <tr>
                                <td>Standings Method</td>
                                <td>
                                    <select name="special_standingsmethod" id="special_standingsmethod" onchange="toggleWinPoints('special')">
                                        <option value="points" selected>Points-based (Win/Loss)</option>
                                        <option value="leg_diff">Leg Difference Only</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="special_winpoints_row">
                                <td>Points for Win</td>
                                <td>
                                    <input type="number" name="special_winpoints" value="2" min="0" max="10" required style="width: 60px;">
                                    <small>(Traditional: 2 points, Modern: 3 points)</small>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="subsection">
                        <h3>Playoff Settings</h3>
                        
                        <label class="inline">
                            <input type="checkbox" id="special_has_playoffs" name="special_has_playoffs" value="1" onchange="togglePlayoffs('special')" checked>
                            Include Top 4 Playoffs
                        </label>
                        
                        <div id="special_playoff_settings" style="display: block; margin-top: 15px;">
                            <label>Playoff match format - First to X sets:</label>
                            <input type="number" name="special_playoff_sets" min="1" value="1">
                            
                            <label>Each set - First to X legs:</label>
                            <input type="number" name="special_playoff_legs" min="1" value="5">
                            
                            <label class="inline">
                                <input type="checkbox" name="special_has_third" value="1" checked>
                                Include 3rd place match
                            </label>
                            
                            <br><br>
                            <label class="inline">
                                <input type="checkbox" id="special_different_final" name="special_different_final" value="1" onchange="toggleFinalFormat('special')">
                                Different format for final match
                            </label>
                            
                            <div id="special_final_settings" style="display: none; margin-top: 15px; padding-left: 20px;">
                                <label>Final match format - First to X sets:</label>
                                <input type="number" name="special_final_sets" min="1" value="1">
                                
                                <label>Each set - First to X legs:</label>
                                <input type="number" name="special_final_legs" min="1" value="5">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <h2>Scoring Scheme</h2>
                <p>Define how points are awarded for each matchday:</p>
                
                <h3>Group Phase Positions</h3>
                <table>
                    <tr>
                        <th>Position</th>
                        <th>Points</th>
                    </tr>
                    <tr>
                        <td>1st Place</td>
                        <td><input type="number" name="score_group_1" min="0" value="5" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>2nd Place</td>
                        <td><input type="number" name="score_group_2" min="0" value="3" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>3rd Place</td>
                        <td><input type="number" name="score_group_3" min="0" value="1" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>4th Place</td>
                        <td><input type="number" name="score_group_4" min="0" value="0" required style="width: 60px;"></td>
                    </tr>
                </table>

                <div id="regular_playoff_scoring">
                <h3>Final Positions (After Playoffs)</h3>
                    <table>
                        <tr>
                            <th>Position</th>
                            <th>Points</th>
                        </tr>
                        <tr>
                            <td>Winner (1st)</td>
                            <td><input type="number" name="score_final_1" min="0" value="10" required style="width: 60px;"></td>
                        </tr>
                        <tr>
                            <td>Runner-up (2nd)</td>
                            <td><input type="number" name="score_final_2" min="0" value="7" required style="width: 60px;"></td>
                        </tr>
                        <tr>
                            <td>3rd Place</td>
                            <td><input type="number" name="score_final_3" min="0" value="5" required style="width: 60px;"></td>
                        </tr>
                        <tr>
                            <td>4th Place</td>
                            <td><input type="number" name="score_final_4" min="0" value="3" required style="width: 60px;"></td>
                        </tr>
                    </table>
                </div>
                
                <h3>Best Statistics Bonuses</h3>
                <table>
                    <tr>
                        <th>Statistic</th>
                        <th>Bonus Points</th>
                    </tr>
                    <tr>
                        <td>Highest 3 Dart Average</td>
                        <td><input type="number" name="score_best_3da" min="0" value="1" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Highest Double %</td>
                        <td><input type="number" name="score_best_dbl" min="0" value="1" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Best Highscore</td>
                        <td><input type="number" name="score_best_hs" min="0" value="2" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Highest Checkout</td>
                        <td><input type="number" name="score_best_hco" min="0" value="2" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Best Leg</td>
                        <td><input type="number" name="score_best_leg" min="0" value="2" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Most 180s</td>
                        <td><input type="number" name="score_most_180s" min="0" value="1" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Most 140+ Scores</td>
                        <td><input type="number" name="score_most_140s" min="0" value="1" required style="width: 60px;"></td>
                    </tr>
                    <tr>
                        <td>Most 100+ Scores</td>
                        <td><input type="number" name="score_most_100s" min="0" value="1" required style="width: 60px;"></td>
                    </tr>
                </table>
            </div>
            
            <input type="submit" name="generate_tournament" value="Generate Tournament" onclick="return confirm('This will create all matchdays and matches. Continue?');">
        </form>
        <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <hr style="margin-top: 40px;">
    <p>
        <a href="index.php">Home</a> | 
        <a href="matchdays.php">Matches Overview</a>
        <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']): ?>
            | <a href="players.php">Player Management</a>
            | <a href="matchday_setup.php">Tournament Setup</a>
            | <a href="import_stats.php">Import Stats</a>
            | <a href="index.php?logout=1">Logout</a>
        <?php else: ?>
            | <a href="index.php#login">Login</a>
        <?php endif; ?>

    <script>
        function toggleWinPoints(type) {
            const method = document.getElementById(type + '_standingsmethod').value;
            const row = document.getElementById(type + '_winpoints_row');
            
            if (method === 'points') {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }

        function showSetupForm() {
            // Hide the warning message
            var warningDiv = event.target.parentElement;
            warningDiv.style.display = 'none';
            
            // Hide the add matchday section if it exists
            var addMatchdaySection = document.getElementById('add_matchday_section');
            if (addMatchdaySection) {
                addMatchdaySection.style.display = 'none';
            }
            
            // Show the setup form
            document.getElementById('setup_form').style.display = 'block';
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleWinPoints('regular');
            if (document.getElementById('special_standingsmethod')) {
                toggleWinPoints('special');
            }
        });
    </script>

</body>
</html>


