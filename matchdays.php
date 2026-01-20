<?php
// matchdays.php - Matchday Management
session_start();

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';
$sets_file = 'tables/sets.csv';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_matchday'])) {
        updateMatchday($_POST['id'], $_POST['date'], $_POST['location']);
        header('Location: matchdays.php');
        exit;
    }
    
    if (isset($_POST['assign_playoffs'])) {
        assignPlayoffPlayers($_POST['matchday_id'], $_POST);
        header('Location: matchdays.php?view=' . $_POST['matchday_id']);
        exit;
    }
    
    if (isset($_POST['add_set'])) {
        $result = addSet($_POST);
        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
        } else {
            $_SESSION['error'] = $result['message'];
        }
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '&edit_match=' . $_POST['match_id']);
        exit;
    }
    
    if (isset($_POST['delete_set'])) {
        deleteSet($_POST['set_id']);
        header('Location: matchdays.php?view=' . $_POST['matchday_id'] . '&edit_match=' . $_POST['match_id']);
        exit;
    }
}

// Load data
$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// View specific matchday
$view_matchday = isset($_GET['view']) ? intval($_GET['view']) : null;
$edit_matchday = isset($_GET['edit']) ? intval($_GET['edit']) : null;
$edit_match = isset($_GET['edit_match']) ? intval($_GET['edit_match']) : null;

// Functions
function loadPlayers() {
    global $players_file;
    $players = [];
    if (!empty($players_file) && file_exists($players_file) && ($fp = fopen($players_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $players[$row[0]] = ['id' => $row[0], 'name' => $row[1], 'nickname' => $row[2]];
        }
        fclose($fp);
    }
    return $players;
}

function loadMatchdays() {
    global $matchdays_file;
    $matchdays = [];
    if (!empty($matchdays_file) && file_exists($matchdays_file) && ($fp = fopen($matchdays_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $matchdays[] = ['id' => $row[0], 'date' => $row[1], 'location' => $row[2]];
        }
        fclose($fp);
    }
    return $matchdays;
}

function loadMatches() {
    global $matches_file;
    $matches = [];
    if (!empty($matches_file) && file_exists($matches_file) && ($fp = fopen($matches_file, 'r')) !== false) {
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

function getMatchdayById($id) {
    global $matchdays;
    foreach ($matchdays as $md) {
        if ($md['id'] == $id) return $md;
    }
    return null;
}

function getMatchesByMatchday($matchday_id) {
    global $all_matches;
    return array_filter($all_matches, function($m) use ($matchday_id) {
        return $m['matchdayid'] == $matchday_id;
    });
}

function updateMatchday($id, $date, $location) {
    global $matchdays_file;
    
    // Load current matchdays
    $matchdays = loadMatchdays();
    
    $fp = fopen($matchdays_file, 'w');
    fputcsv($fp, ['id', 'date', 'location']);
    foreach ($matchdays as $md) {
        if ($md['id'] == $id) {
            fputcsv($fp, [$id, $date, $location]);
        } else {
            fputcsv($fp, [$md['id'], $md['date'], $md['location']]);
        }
    }
    fclose($fp);
}

function assignPlayoffPlayers($matchday_id, $data) {
    global $matches_file, $all_matches;
    
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    foreach ($all_matches as $match) {
        if ($match['matchdayid'] == $matchday_id && $match['phase'] != 'group') {
            // Update playoff match with assigned players
            $phase = $match['phase'];
            $p1_key = 'playoff_' . $phase . '_p1';
            $p2_key = 'playoff_' . $phase . '_p2';
            
            $player1id = isset($data[$p1_key]) ? $data[$p1_key] : $match['player1id'];
            $player2id = isset($data[$p2_key]) ? $data[$p2_key] : $match['player2id'];
            
            fputcsv($fp, [
                $match['id'],
                $match['matchdayid'],
                $match['phase'],
                $match['firsttosets'],
                $match['firsttolegs'],
                $player1id,
                $player2id,
                $match['sets1'],
                $match['sets2']
            ]);
        } else {
            // Keep other matches unchanged
            fputcsv($fp, [
                $match['id'],
                $match['matchdayid'],
                $match['phase'],
                $match['firsttosets'],
                $match['firsttolegs'],
                $match['player1id'],
                $match['player2id'],
                $match['sets1'],
                $match['sets2']
            ]);
        }
    }
    fclose($fp);
}

function loadSets($match_id) {
    global $sets_file;
    $sets = [];
    if (!empty($sets_file) && file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[1] == $match_id) {
                $sets[] = [
                    'id' => $row[0],
                    'matchid' => $row[1],
                    'player1id' => $row[2],
                    'player2id' => $row[3],
                    'legs1' => $row[4],
                    'legs2' => $row[5],
                    'darts1' => $row[6],
                    'darts2' => $row[7],
                    '3da1' => $row[8],
                    '3da2' => $row[9],
                    'dblattempts1' => $row[10],
                    'dblattempts2' => $row[11],
                    'highscore1' => $row[12],
                    'highscore2' => $row[13],
                    'highco1' => $row[14],
                    'highco2' => $row[15]
                ];
            }
        }
        fclose($fp);
    }
    return $sets;
}

function getNextSetId() {
    global $sets_file;
    $max_id = 0;
    if (!empty($sets_file) && file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] > $max_id) $max_id = $row[0];
        }
        fclose($fp);
    }
    return $max_id + 1;
}

function addSet($data) {
    global $sets_file, $matches_file;
    
    $all_matches = loadMatches();
    $match = null;
    foreach ($all_matches as $m) {
        if ($m['id'] == $data['match_id']) {
            $match = $m;
            break;
        }
    }
    
    if (!$match) {
        return ['success' => false, 'message' => 'Match not found.'];
    }
    
    // Validate leg counts
    $legs1 = intval($data['legs1']);
    $legs2 = intval($data['legs2']);
    $firsttolegs = intval($match['firsttolegs']);
    
    if ($legs1 > $firsttolegs || $legs2 > $firsttolegs) {
        return ['success' => false, 'message' => "Legs won cannot exceed first-to-" . $firsttolegs . " format."];
    }
    
    if ($legs1 != $firsttolegs && $legs2 != $firsttolegs) {
        return ['success' => false, 'message' => "One player must reach " . $firsttolegs . " legs to win the set."];
    }
    
    // Check if match is already won
    $existing_sets = loadSets($data['match_id']);
    $sets1 = 0;
    $sets2 = 0;
    
    foreach ($existing_sets as $set) {
        if (intval($set['legs1']) > intval($set['legs2'])) {
            $sets1++;
        } elseif (intval($set['legs2']) > intval($set['legs1'])) {
            $sets2++;
        }
    }
    
    $firsttosets = intval($match['firsttosets']);
    if ($sets1 >= $firsttosets || $sets2 >= $firsttosets) {
        return ['success' => false, 'message' => "Match is already won (first-to-" . $firsttosets . " sets)."];
    }
    
    $set_id = getNextSetId();
    
    // Calculate 3DA from entered values
    $da1 = floatval($data['3da1']);
    $da2 = floatval($data['3da2']);
    
    // Calculate darts thrown from 3DA and legs
    $darts1 = ($da1 > 0) ? round(($da1 * $legs1) / 3) : 0;
    $darts2 = ($da2 > 0) ? round(($da2 * $legs2) / 3) : 0;
    
    $fp = fopen($sets_file, 'a');
    fputcsv($fp, [
        $set_id,
        $data['match_id'],
        $match['player1id'],
        $match['player2id'],
        $legs1,
        $legs2,
        $darts1,
        $darts2,
        $da1,
        $da2,
        $data['dblattempts1'],
        $data['dblattempts2'],
        $data['highscore1'],
        $data['highscore2'],
        $data['highco1'],
        $data['highco2']
    ]);
    fclose($fp);
    
    // Update match score
    if ($legs1 > $legs2) {
        $sets1++;
    } else {
        $sets2++;
    }
    
    // Update matches.csv
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    foreach ($all_matches as $m) {
        if ($m['id'] == $data['match_id']) {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $sets1, $sets2]);
        } else {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $m['sets1'], $m['sets2']]);
        }
    }
    fclose($fp);
    
    return ['success' => true, 'message' => 'Set added successfully.'];
}

function deleteSet($set_id) {
    global $sets_file;
    
    $all_sets = [];
    if (($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] != $set_id) {
                $all_sets[] = $row;
            }
        }
        fclose($fp);
    }
    
    $fp = fopen($sets_file, 'w');
    fputcsv($fp, ['id', 'matchid', 'player1id', 'player2id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2']);
    foreach ($all_sets as $set) {
        fputcsv($fp, $set);
    }
    fclose($fp);
}

function getPlayerName($player_id) {
    global $players;
    if ($player_id == 0) return 'TBD';
    if (isset($players[$player_id])) {
        $p = $players[$player_id];
        return $p['nickname'] ? $p['name'] . ' (' . $p['nickname'] . ')' : $p['name'];
    }
    return 'Unknown';
}

function getPhaseLabel($phase) {
    $labels = [
        'group' => 'Group Phase',
        'semi1' => 'Semi-Final 1',
        'semi2' => 'Semi-Final 2',
        'third' => '3rd Place',
        'final' => 'Final'
    ];
    return isset($labels[$phase]) ? $labels[$phase] : $phase;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Matchday Management</title>
    <link rel="stylesheet" href="styles.css">
    <!--<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .group-phase { background-color: #f9f9f9; }
        .playoff-phase { background-color: #fff3e0; }
        input[type="text"], input[type="date"], select { padding: 5px; margin: 5px 0; }
        input[type="submit"], button { padding: 8px 15px; margin: 5px; }
        .info { background-color: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .warning { background-color: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .section { margin: 30px 0; padding: 15px; border: 1px solid #ddd; background-color: #fafafa; }
        .match-format { font-size: 0.9em; color: #666; }
    </style>-->
</head>
<body>
    <h1>Matchday Management</h1>
    
    <?php if (empty($matchdays)): ?>
        <div class="info">
            No matchdays created yet. Please run the tournament setup first.<br>
            <a href="setup.php"><button type="button">Go to Tournament Setup</button></a>
        </div>
    <?php elseif ($edit_matchday): ?>
        <!-- Edit Matchday Form -->
        <?php $md = getMatchdayById($edit_matchday); ?>
        <div class="section">
            <h2>Edit Matchday <?php echo $md['id']; ?></h2>
            <form method="POST">
                <input type="hidden" name="id" value="<?php echo $md['id']; ?>">
                
                <label>Date:</label><br>
                <input type="date" name="date" value="<?php echo htmlspecialchars($md['date']); ?>"><br>
                
                <label>Location:</label><br>
                <input type="text" name="location" value="<?php echo htmlspecialchars($md['location']); ?>" size="50"><br>
                
                <input type="submit" name="update_matchday" value="Save">
                <a href="matchdays.php"><button type="button">Cancel</button></a>
            </form>
        </div>
    <?php elseif ($view_matchday): ?>
        <!-- View Matchday Details -->
        <?php 
        $md = getMatchdayById($view_matchday);
        $matches = getMatchesByMatchday($view_matchday);
        $group_matches = array_filter($matches, function($m) { return $m['phase'] == 'group'; });
        $playoff_matches = array_filter($matches, function($m) { return $m['phase'] != 'group'; });
        ?>
        
        <h2>Matchday <?php echo $md['id']; ?></h2>
        <p>
            <strong>Date:</strong> <?php echo $md['date'] ? $md['date'] : 'Not set'; ?> | 
            <strong>Location:</strong> <?php echo $md['location'] ? $md['location'] : 'Not set'; ?> |
            <?php if ($is_admin): ?>
                | <a href="matchdays.php?edit=<?php echo $md['id']; ?>"><button>Edit Date/Location</button></a>
            <?php endif; ?>
        </p>
        
        <!-- Group Phase Matches -->
        <h3>Group Phase Matches</h3>
        <?php if (empty($group_matches)): ?>
            <p>No group matches for this matchday.</p>
        <?php else: ?>
            <?php 
            $first_match = array_values($group_matches)[0];
            ?>
            <p class="match-format">Format: First to <?php echo $first_match['firsttosets']; ?> sets (each set first to <?php echo $first_match['firsttolegs']; ?> legs)</p>
            
            <?php foreach ($group_matches as $match): 
                // Check if this match is being edited
                $is_editing = ($edit_match == $match['id']);
                
                if ($is_editing):
                    // Show score entry form
                    $match_sets = loadSets($match['id']);
                    $sets_won_p1 = 0;
                    $sets_won_p2 = 0;
                    foreach ($match_sets as $set) {
                        if (intval($set['legs1']) > intval($set['legs2'])) $sets_won_p1++;
                        elseif (intval($set['legs2']) > intval($set['legs1'])) $sets_won_p2++;
                    }
                    $match_won = ($sets_won_p1 >= intval($match['firsttosets']) || $sets_won_p2 >= intval($match['firsttosets']));
                ?>
                
                <div class="section">
                    <h4>Match #<?php echo $match['id']; ?> - Score Entry</h4>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="warning"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="info"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <?php endif; ?>
                    
                    <p><strong><?php echo getPlayerName($match['player1id']); ?></strong> vs <strong><?php echo getPlayerName($match['player2id']); ?></strong></p>
                    <p>Current Score: <?php echo $sets_won_p1; ?> : <?php echo $sets_won_p2; ?> sets</p>
                    
                    <?php if (!empty($match_sets)): ?>
                        <h5>Entered Sets</h5>
                        <table style="margin-bottom: 20px;">
                            <tr>
                                <th>Set</th>
                                <th>Player</th>
                                <th>Legs</th>
                                <th>Avg</th>
                                <th>Double Att.</th>
                                <th>Highscore</th>
                                <th>Highest CO</th>
                                <th>Action</th>
                            </tr>
                            <?php 
                            $set_num = 1;
                            foreach ($match_sets as $set): 
                            ?>
                            <tr>
                                <td rowspan="2"><?php echo $set_num; ?></td>
                                <td><?php echo getPlayerName($match['player1id']); ?></td>
                                <td><?php echo $set['legs1']; ?></td>
                                <td><?php echo $set['3da1']; ?></td>
                                <td><?php echo $set['dblattempts1']; ?></td>
                                <td><?php echo $set['highscore1']; ?></td>
                                <td><?php echo $set['highco1']; ?></td>
                                <td rowspan="2">
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this set?');">
                                        <input type="hidden" name="set_id" value="<?php echo $set['id']; ?>">
                                        <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                        <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                        <input type="submit" name="delete_set" value="Delete">
                                    </form>
                                </td>
                            </tr>
                            <tr>
                                <td><?php echo getPlayerName($match['player2id']); ?></td>
                                <td><?php echo $set['legs2']; ?></td>
                                <td><?php echo $set['3da2']; ?></td>
                                <td><?php echo $set['dblattempts2']; ?></td>
                                <td><?php echo $set['highscore2']; ?></td>
                                <td><?php echo $set['highco2']; ?></td>
                            </tr>
                            <?php 
                            $set_num++;
                            endforeach; 
                            ?>
                        </table>
                    <?php endif; ?>
                    
                    <?php if (!$match_won): ?>
                        <h5>Add Set <?php echo count($match_sets) + 1; ?></h5>
                        <form method="POST">
                            <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                            <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                            
                            <table>
                                <tr>
                                    <th>Player</th>
                                    <th>Legs Won</th>
                                    <th>Avg</th>
                                    <th>Double Att.</th>
                                    <th>Highscore</th>
                                    <th>Highest CO</th>
                                </tr>
                                <tr>
                                    <td><?php echo getPlayerName($match['player1id']); ?></td>
                                    <td><input type="number" name="legs1" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="3da1" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                    <td><input type="number" name="dblattempts1" min="0" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highscore1" min="0" max="180" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highco1" min="0" max="170" value="0" required style="width: 60px;"></td>
                                </tr>
                                <tr>
                                    <td><?php echo getPlayerName($match['player2id']); ?></td>
                                    <td><input type="number" name="legs2" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="3da2" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                    <td><input type="number" name="dblattempts2" min="0" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highscore2" min="0" max="180" value="0" required style="width: 60px;"></td>
                                    <td><input type="number" name="highco2" min="0" max="170" value="0" required style="width: 60px;"></td>
                                </tr>
                            </table>
                            
                            <input type="submit" name="add_set" value="Add Set">
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Done</button></a>
                        </form>
                    <?php else: ?>
                        <div class="info">Match is complete!</div>
                        <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Back to Overview</button></a>
                    <?php endif; ?>
                </div>
                
                <?php else:
                    // Show match summary
                    // Get detailed stats from sets.csv
                    $sets_data = [];
                    $p1_stats = ['total_legs' => 0, 'total_darts' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0];
                    $p2_stats = ['total_legs' => 0, 'total_darts' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0];
                    
                    $sets_file = 'tables/sets.csv';
                    if (!empty($sets_file) &&  file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                        $header = fgetcsv($fp);
                        while (($row = fgetcsv($fp)) !== false) {
                            if ($row[1] == $match['id']) { // matchid
                                $sets_data[] = [
                                    'legs1' => intval($row[4]),
                                    'legs2' => intval($row[5]),
                                    'darts1' => intval($row[6]),
                                    'darts2' => intval($row[7]),
                                    'dblattempts1' => intval($row[10]),
                                    'dblattempts2' => intval($row[11])
                                ];
                                
                                $p1_stats['total_legs'] += intval($row[4]);
                                $p2_stats['total_legs'] += intval($row[5]);
                                $p1_stats['total_darts'] += intval($row[6]);
                                $p2_stats['total_darts'] += intval($row[7]);
                                $p1_stats['dbl_attempts'] += intval($row[10]);
                                $p2_stats['dbl_attempts'] += intval($row[11]);
                                
                                // Count successful doubles (= legs won)
                                $p1_stats['dbl_hit'] += intval($row[4]);
                                $p2_stats['dbl_hit'] += intval($row[5]);
                            }
                        }
                        fclose($fp);
                    }
                    
                    $p1_3da = ($p1_stats['total_legs'] > 0) ? round(($p1_stats['total_darts'] / $p1_stats['total_legs']) * 3, 2) : '-';
                    $p2_3da = ($p2_stats['total_legs'] > 0) ? round(($p2_stats['total_darts'] / $p2_stats['total_legs']) * 3, 2) : '-';
                    $p1_dbl = ($p1_stats['dbl_attempts'] > 0) ? round(($p1_stats['dbl_hit'] / $p1_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                    $p2_dbl = ($p2_stats['dbl_attempts'] > 0) ? round(($p2_stats['dbl_hit'] / $p2_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                    
                    $show_sets = intval($first_match['firsttosets']) > 1;
                ?>
                
                <table style="margin-bottom: 20px;">
                    <tr>
                        <th colspan="<?php echo $show_sets ? (4 + count($sets_data)) : 4; ?>">
                            Match #<?php echo $match['id']; ?>
                            <?php if ($is_admin): ?>
                                <a href="matchdays.php?view=<?php echo $md['id']; ?>&edit_match=<?php echo $match['id']; ?>" style="float: right;"><button type="button">Enter/Edit Scores</button></a>
                            <?php endif; ?>
                        </th>
                    </tr>
                    <tr>
                        <th class="player-name">Player</th>
                        <?php if ($show_sets): ?>
                            <th>Sets</th>
                            <?php for ($i = 1; $i <= count($sets_data); $i++): ?>
                                <th>Set <?php echo $i; ?></th>
                            <?php endfor; ?>
                        <?php else: ?>
                            <th>Legs</th>
                        <?php endif; ?>
                        <th>Avg</th>
                        <th>Doubles %</th>
                    </tr>
                    <tr>
                        <td class="player-name"><?php echo getPlayerName($match['player1id']); ?></td>
                        <?php if ($show_sets): ?>
                            <td><strong><?php echo $match['sets1']; ?></strong></td>
                            <?php foreach ($sets_data as $set): ?>
                                <td><?php echo $set['legs1']; ?></td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td><strong><?php echo $p1_stats['total_legs']; ?></strong></td>
                        <?php endif; ?>
                        <td><?php echo $p1_3da; ?></td>
                        <td><?php echo $p1_dbl; ?></td>
                    </tr>
                    <tr>
                        <td class="player-name"><?php echo getPlayerName($match['player2id']); ?></td>
                        <?php if ($show_sets): ?>
                            <td><strong><?php echo $match['sets2']; ?></strong></td>
                            <?php foreach ($sets_data as $set): ?>
                                <td><?php echo $set['legs2']; ?></td>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <td><strong><?php echo $p2_stats['total_legs']; ?></strong></td>
                        <?php endif; ?>
                        <td><?php echo $p2_3da; ?></td>
                        <td><?php echo $p2_dbl; ?></td>
                    </tr>
                </table>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Group Phase Standings -->
        <?php if (!empty($group_matches)): ?>
            <h3>Group Phase Standings</h3>
            <?php
            // Calculate standings
            $standings = [];
            foreach ($players as $player) {
                $standings[$player['id']] = [
                    'name' => getPlayerName($player['id']),
                    'played' => 0,
                    'won' => 0,
                    'lost' => 0,
                    'legs_for' => 0,
                    'legs_against' => 0,
                    'points' => 0,
                    'total_darts' => 0,
                    'total_legs' => 0
                ];
            }
            
            // Process group matches
            foreach ($group_matches as $match) {
                $p1id = $match['player1id'];
                $p2id = $match['player2id'];
                $sets1 = intval($match['sets1']);
                $sets2 = intval($match['sets2']);
                
                // Only count completed matches
                if ($sets1 > 0 || $sets2 > 0) {
                    $standings[$p1id]['played']++;
                    $standings[$p2id]['played']++;
                    
                    // Determine winner
                    if ($sets1 > $sets2) {
                        $standings[$p1id]['won']++;
                        $standings[$p1id]['points'] += 2;
                        $standings[$p2id]['lost']++;
                    } elseif ($sets2 > $sets1) {
                        $standings[$p2id]['won']++;
                        $standings[$p2id]['points'] += 2;
                        $standings[$p1id]['lost']++;
                    }
                    
                    // Get legs from sets.csv for this match
                    $sets_file = 'tables/sets.csv';
                    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                        $header = fgetcsv($fp);
                        while (($row = fgetcsv($fp)) !== false) {
                            if ($row[1] == $match['id']) { // matchid column
                                $legs1 = intval($row[4]);
                                $legs2 = intval($row[5]);
                                $darts1 = intval($row[6]);
                                $darts2 = intval($row[7]);
                                
                                $standings[$p1id]['legs_for'] += $legs1;
                                $standings[$p1id]['legs_against'] += $legs2;
                                $standings[$p2id]['legs_for'] += $legs2;
                                $standings[$p2id]['legs_against'] += $legs1;
                                
                                // Calculate 3DA (only if darts > 0)
                                if ($darts1 > 0) {
                                    $standings[$p1id]['total_darts'] += $darts1;
                                    $standings[$p1id]['total_legs'] += $legs1;
                                }
                                if ($darts2 > 0) {
                                    $standings[$p2id]['total_darts'] += $darts2;
                                    $standings[$p2id]['total_legs'] += $legs2;
                                }
                            }
                        }
                        fclose($fp);
                    }
                }
            }
            
            // Sort by points, then legs difference
            usort($standings, function($a, $b) {
                if ($b['points'] != $a['points']) {
                    return $b['points'] - $a['points'];
                }
                $diff_a = $a['legs_for'] - $a['legs_against'];
                $diff_b = $b['legs_for'] - $b['legs_against'];
                if ($diff_b != $diff_a) {
                    return $diff_b - $diff_a;
                }
                return $b['legs_for'] - $a['legs_for'];
            });
            ?>
            
            <table>
                <tr>
                    <th>Pos</th>
                    <th>Player</th>
                    <th>Played</th>
                    <th>Wins</th>
                    <th>Losses</th>
                    <th>Legs+</th>
                    <th>Legs-</th>
                    <th>Leg Diff</th>
                    <th>Points</th>
                    <th>Avg</th>
                </tr>
                <?php 
                $pos = 1;
                foreach ($standings as $s): 
                    $three_da = ($s['total_legs'] > 0) ? round(($s['total_darts'] / $s['total_legs']) * 3, 2) : '-';
                ?>
                <tr>
                    <td><?php echo $pos++; ?></td>
                    <td><?php echo $s['name']; ?></td>
                    <td><?php echo $s['played']; ?></td>
                    <td><?php echo $s['won']; ?></td>
                    <td><?php echo $s['lost']; ?></td>
                    <td><?php echo $s['legs_for']; ?></td>
                    <td><?php echo $s['legs_against']; ?></td>
                    <td><?php echo $s['legs_for'] - $s['legs_against']; ?></td>
                    <td><strong><?php echo $s['points']; ?></strong></td>
                    <td><?php echo $three_da; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <!-- Playoff Matches -->
        <?php if (!empty($playoff_matches)): ?>
            <h3>Playoff Matches</h3>
            
            <?php
            $has_unassigned = false;
            foreach ($playoff_matches as $match) {
                if ($match['player1id'] == 0 || $match['player2id'] == 0) {
                    $has_unassigned = true;
                    break;
                }
            }
            ?>
            
            <?php if ($has_unassigned && $is_admin): ?>
                <div class="info">
                    <strong>Note:</strong> Some playoff matches don't have players assigned yet. Assign them based on group phase standings.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                    <table>
                        <tr>
                            <th>Match</th>
                            <th>Player 1</th>
                            <th>Player 2</th>
                            <th>Format</th>
                        </tr>
                        <?php foreach ($playoff_matches as $match): ?>
                        <tr class="playoff-phase">
                            <td><?php echo getPhaseLabel($match['phase']); ?></td>
                            <td>
                                <select name="playoff_<?php echo $match['phase']; ?>_p1">
                                    <option value="0">TBD</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $match['player1id'] == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo getPlayerName($p['id']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="playoff_<?php echo $match['phase']; ?>_p2">
                                    <option value="0">TBD</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" <?php echo $match['player2id'] == $p['id'] ? 'selected' : ''; ?>>
                                            <?php echo getPlayerName($p['id']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="match-format">First to <?php echo $match['firsttosets']; ?> sets (each to <?php echo $match['firsttolegs']; ?> legs)</td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <input type="submit" name="assign_playoffs" value="Assign Players">
                </form>
            <?php else: ?>
                <?php 
                $first_playoff = array_values($playoff_matches)[0];
                $show_sets = intval($first_playoff['firsttosets']) > 1;
                ?>
                <p class="match-format">Format: First to <?php echo $first_playoff['firsttosets']; ?> sets (each set first to <?php echo $first_playoff['firsttolegs']; ?> legs)</p>
                
                <?php foreach ($playoff_matches as $match): 
                    // Check if this match is being edited
                    $is_editing = ($edit_match == $match['id']);
                    
                    if ($is_editing):
                        // Show score entry form
                        $match_sets = loadSets($match['id']);
                        $sets_won_p1 = 0;
                        $sets_won_p2 = 0;
                        foreach ($match_sets as $set) {
                            if (intval($set['legs1']) > intval($set['legs2'])) $sets_won_p1++;
                            elseif (intval($set['legs2']) > intval($set['legs1'])) $sets_won_p2++;
                        }
                        $match_won = ($sets_won_p1 >= intval($match['firsttosets']) || $sets_won_p2 >= intval($match['firsttosets']));
                    ?>
                    
                    <div class="section">
                        <h4><?php echo getPhaseLabel($match['phase']); ?> - Score Entry</h4>
                        
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="warning"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="info"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                        <?php endif; ?>
                        
                        <p><strong><?php echo getPlayerName($match['player1id']); ?></strong> vs <strong><?php echo getPlayerName($match['player2id']); ?></strong></p>
                        <p>Current Score: <?php echo $sets_won_p1; ?> : <?php echo $sets_won_p2; ?> sets</p>
                        
                        <?php if (!empty($match_sets)): ?>
                            <h5>Entered Sets</h5>
                            <table style="margin-bottom: 20px;">
                                <tr>
                                    <th>Set</th>
                                    <th>Player</th>
                                    <th>Legs</th>
                                    <th>Avg</th>
                                    <th>Double Att.</th>
                                    <th>High Score</th>
                                    <th>High CO</th>
                                    <th>Action</th>
                                </tr>
                                <?php 
                                $set_num = 1;
                                foreach ($match_sets as $set): 
                                ?>
                                <tr>
                                    <td rowspan="2"><?php echo $set_num; ?></td>
                                    <td><?php echo getPlayerName($match['player1id']); ?></td>
                                    <td><?php echo $set['legs1']; ?></td>
                                    <td><?php echo $set['3da1']; ?></td>
                                    <td><?php echo $set['dblattempts1']; ?></td>
                                    <td><?php echo $set['highscore1']; ?></td>
                                    <td><?php echo $set['highco1']; ?></td>
                                    <td rowspan="2">
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this set?');">
                                            <input type="hidden" name="set_id" value="<?php echo $set['id']; ?>">
                                            <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                            <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                            <input type="submit" name="delete_set" value="Delete">
                                        </form>
                                    </td>
                                </tr>
                                <tr>
                                    <td><?php echo getPlayerName($match['player2id']); ?></td>
                                    <td><?php echo $set['legs2']; ?></td>
                                    <td><?php echo $set['3da2']; ?></td>
                                    <td><?php echo $set['dblattempts2']; ?></td>
                                    <td><?php echo $set['highscore2']; ?></td>
                                    <td><?php echo $set['highco2']; ?></td>
                                </tr>
                                <?php 
                                $set_num++;
                                endforeach; 
                                ?>
                            </table>
                        <?php endif; ?>
                        
                        <?php if (!$match_won): ?>
                            <h5>Add Set <?php echo count($match_sets) + 1; ?></h5>
                            <form method="POST">
                                <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                <input type="hidden" name="matchday_id" value="<?php echo $md['id']; ?>">
                                
                                <table>
                                    <tr>
                                        <th>Player</th>
                                        <th>Legs Won</th>
                                        <th>Avg</th>
                                        <th>Double Att.</th>
                                        <th>Highscore</th>
                                        <th>Highest CO</th>
                                    </tr>
                                    <tr>
                                        <td><?php echo getPlayerName($match['player1id']); ?></td>
                                        <td><input type="number" name="legs1" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="3da1" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                        <td><input type="number" name="dblattempts1" min="0" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highscore1" min="0" max="180" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highco1" min="0" max="170" value="0" required style="width: 60px;"></td>
                                    </tr>
                                    <tr>
                                        <td><?php echo getPlayerName($match['player2id']); ?></td>
                                        <td><input type="number" name="legs2" min="0" max="<?php echo $match['firsttolegs']; ?>" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="3da2" min="0" max="180" step="0.01" value="0" required style="width: 70px;"></td>
                                        <td><input type="number" name="dblattempts2" min="0" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highscore2" min="0" max="180" value="0" required style="width: 60px;"></td>
                                        <td><input type="number" name="highco2" min="0" max="170" value="0" required style="width: 60px;"></td>
                                    </tr>
                                </table>
                                
                                <input type="submit" name="add_set" value="Add Set">
                                <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Done</button></a>
                            </form>
                        <?php else: ?>
                            <div class="info">Match is complete!</div>
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button type="button">Back to Overview</button></a>
                        <?php endif; ?>
                    </div>
                    
                    <?php else:
                        // Show match summary
                        // Get detailed stats from sets.csv
                        $sets_data = [];
                        $p1_stats = ['total_legs' => 0, 'total_darts' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0];
                        $p2_stats = ['total_legs' => 0, 'total_darts' => 0, 'dbl_attempts' => 0, 'dbl_hit' => 0];
                        
                        $sets_file = 'tables/sets.csv';
                        if (!empty($sets_file) &&  file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
                            $header = fgetcsv($fp);
                            while (($row = fgetcsv($fp)) !== false) {
                                if ($row[1] == $match['id']) { // matchid
                                    $sets_data[] = [
                                        'legs1' => intval($row[4]),
                                        'legs2' => intval($row[5]),
                                        'darts1' => intval($row[6]),
                                        'darts2' => intval($row[7]),
                                        'dblattempts1' => intval($row[10]),
                                        'dblattempts2' => intval($row[11])
                                    ];
                                    
                                    $p1_stats['total_legs'] += intval($row[4]);
                                    $p2_stats['total_legs'] += intval($row[5]);
                                    $p1_stats['total_darts'] += intval($row[6]);
                                    $p2_stats['total_darts'] += intval($row[7]);
                                    $p1_stats['dbl_attempts'] += intval($row[10]);
                                    $p2_stats['dbl_attempts'] += intval($row[11]);
                                    
                                    // Count successful doubles (= legs won)
                                    $p1_stats['dbl_hit'] += intval($row[4]);
                                    $p2_stats['dbl_hit'] += intval($row[5]);
                                }
                            }
                            fclose($fp);
                        }
                        
                        $p1_3da = ($p1_stats['total_legs'] > 0) ? round(($p1_stats['total_darts'] / $p1_stats['total_legs']) * 3, 2) : '-';
                        $p2_3da = ($p2_stats['total_legs'] > 0) ? round(($p2_stats['total_darts'] / $p2_stats['total_legs']) * 3, 2) : '-';
                        $p1_dbl = ($p1_stats['dbl_attempts'] > 0) ? round(($p1_stats['dbl_hit'] / $p1_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                        $p2_dbl = ($p2_stats['dbl_attempts'] > 0) ? round(($p2_stats['dbl_hit'] / $p2_stats['dbl_attempts']) * 100, 1) . '%' : '-';
                    ?>
                    
                    <table style="margin-bottom: 20px;">
                        <tr>
                            <th colspan="<?php echo $show_sets ? (4 + count($sets_data)) : 4; ?>">
                                <?php echo getPhaseLabel($match['phase']); ?>
                                <?php if ($is_admin): ?>
                                    <a href="matchdays.php?view=<?php echo $md['id']; ?>&edit_match=<?php echo $match['id']; ?>" style="float: right;"><button type="button">Enter/Edit Scores</button></a>
                                <?php endif; ?>
                            </th>
                        </tr>
                        <tr>
                            <th class="player-name">Player</th>
                            <?php if ($show_sets): ?>
                                <th>Sets</th>
                                <?php for ($i = 1; $i <= count($sets_data); $i++): ?>
                                    <th>Set <?php echo $i; ?></th>
                                <?php endfor; ?>
                            <?php else: ?>
                                <th>Legs</th>
                            <?php endif; ?>
                            <th>3DA</th>
                            <th>Dbl%</th>
                        </tr>
                        <tr>
                            <td class="player-name"><?php echo getPlayerName($match['player1id']); ?></td>
                            <?php if ($show_sets): ?>
                                <td><strong><?php echo $match['sets1']; ?></strong></td>
                                <?php foreach ($sets_data as $set): ?>
                                    <td><?php echo $set['legs1']; ?></td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td><strong><?php echo $p1_stats['total_legs']; ?></strong></td>
                            <?php endif; ?>
                            <td><?php echo $p1_3da; ?></td>
                            <td><?php echo $p1_dbl; ?></td>
                        </tr>
                        <tr>
                            <td class="player-name"><?php echo getPlayerName($match['player2id']); ?></td>
                            <?php if ($show_sets): ?>
                                <td><strong><?php echo $match['sets2']; ?></strong></td>
                                <?php foreach ($sets_data as $set): ?>
                                    <td><?php echo $set['legs2']; ?></td>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <td><strong><?php echo $p2_stats['total_legs']; ?></strong></td>
                            <?php endif; ?>
                            <td><?php echo $p2_3da; ?></td>
                            <td><?php echo $p2_dbl; ?></td>
                        </tr>
                    </table>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <?php if ($is_admin): ?>
                    <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button>Edit Player Assignments</button></a>
                <?php endif; ?>
            <?php endif; ?>
        
        <p><a href="matchdays.php"><button type="button">Back to All Matchdays</button></a></p>
        
    <?php else: ?>
        <!-- List All Matchdays -->
        <h2>All Matchdays</h2>
        <table>
            <tr>
                <th>Matchday</th>
                <th>Date</th>
                <th>Location</th>
                <th>Matches</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($matchdays as $md): ?>
            <?php $match_count = count(getMatchesByMatchday($md['id'])); ?>
            <tr>
                <td>Matchday <?php echo $md['id']; ?></td>
                <td><?php echo $md['date'] ? $md['date'] : '<em>Not set</em>'; ?></td>
                <td><?php echo $md['location'] ? htmlspecialchars($md['location']) : '<em>Not set</em>'; ?></td>
                <td><?php echo $match_count; ?> matches</td>
                <td>
                    <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button>View Details</button></a>
                    <?php if ($is_admin): ?>
                        <a href="matchdays.php?edit=<?php echo $md['id']; ?>"><button>Edit</button></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <?php endif; ?>
    
    <p>
        <a href="players.php">Player Management</a> | 
        <a href="matchday_setup.php">Tournament Setup</a> | 
        <a href="index.php">Home</a>
    </p>
</body>
</html>