<?php
// matchday_mgmt.php - Matchday Management

$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_matchday'])) {
        updateMatchday($_POST['id'], $_POST['date'], $_POST['location']);
        header('Location: matchday_mgmt.php');
        exit;
    }
    
    if (isset($_POST['assign_playoffs'])) {
        assignPlayoffPlayers($_POST['matchday_id'], $_POST);
        header('Location: matchday_mgmt.php?view=' . $_POST['matchday_id']);
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

// Functions
function loadPlayers() {
    global $players_file;
    $players = [];
    if (file_exists($players_file) && ($fp = fopen($players_file, 'r')) !== false) {
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
    if (file_exists($matchdays_file) && ($fp = fopen($matchdays_file, 'r')) !== false) {
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
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .group-phase { background-color: #f9f9f9; }
        .playoff-phase { background-color: #fff3e0; }
        input[type="text"], input[type="date"], select { padding: 5px; margin: 5px 0; }
        input[type="submit"], button { padding: 8px 15px; margin: 5px; }
        .info { background-color: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .section { margin: 30px 0; padding: 15px; border: 1px solid #ddd; }
        .match-format { font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <h1>Matchday Management</h1>
    
    <?php if (empty($matchdays)): ?>
        <div class="info">
            No matchdays created yet. Please run the tournament setup first.<br>
            <a href="matchday_setup.php"><button type="button">Go to Tournament Setup</button></a>
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
                <a href="matchday_mgmt.php"><button type="button">Cancel</button></a>
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
            <a href="matchday_mgmt.php?edit=<?php echo $md['id']; ?>"><button>Edit Date/Location</button></a>
        </p>
        
        <!-- Group Phase Matches -->
        <h3>Group Phase Matches</h3>
        <?php if (empty($group_matches)): ?>
            <p>No group matches for this matchday.</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>Match #</th>
                    <th>Player 1</th>
                    <th>Player 2</th>
                    <th>Format</th>
                    <th>Result</th>
                </tr>
                <?php foreach ($group_matches as $match): ?>
                <tr class="group-phase">
                    <td><?php echo $match['id']; ?></td>
                    <td><?php echo getPlayerName($match['player1id']); ?></td>
                    <td><?php echo getPlayerName($match['player2id']); ?></td>
                    <td class="match-format">First to <?php echo $match['firsttosets']; ?> sets (each to <?php echo $match['firsttolegs']; ?> legs)</td>
                    <td><?php echo $match['sets1']; ?> : <?php echo $match['sets2']; ?></td>
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
            
            <?php if ($has_unassigned): ?>
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
                <table>
                    <tr>
                        <th>Match</th>
                        <th>Player 1</th>
                        <th>Player 2</th>
                        <th>Format</th>
                        <th>Result</th>
                    </tr>
                    <?php foreach ($playoff_matches as $match): ?>
                    <tr class="playoff-phase">
                        <td><?php echo getPhaseLabel($match['phase']); ?></td>
                        <td><?php echo getPlayerName($match['player1id']); ?></td>
                        <td><?php echo getPlayerName($match['player2id']); ?></td>
                        <td class="match-format">First to <?php echo $match['firsttosets']; ?> sets (each to <?php echo $match['firsttolegs']; ?> legs)</td>
                        <td><?php echo $match['sets1']; ?> : <?php echo $match['sets2']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <a href="matchdays_mgmt.php?view=<?php echo $md['id']; ?>"><button>Edit Player Assignments</button></a>
            <?php endif; ?>
        <?php endif; ?>
        
        <p><a href="matchday_mgmt.php"><button type="button">Back to All Matchdays</button></a></p>
        
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
                    <a href="matchday_mgmt.php?view=<?php echo $md['id']; ?>"><button>View Details</button></a>
                    <a href="matchday_mgmt.php?edit=<?php echo $md['id']; ?>"><button>Edit</button></a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    
    <p>
        <a href="player_mgmt.php">Player Management</a> | 
        <a href="matchday_setup.php">Tournament Setup</a>
    </p>
</body>
</html>