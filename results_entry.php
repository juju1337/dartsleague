<?php
// results_entry.php - Match Results Entry
session_start();


$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';
$sets_file = 'tables/sets.csv';

// Initialize sets.csv if it doesn't exist
if (!file_exists($sets_file)) {
    $fp = fopen($sets_file, 'w');
    fputcsv($fp, ['id', 'matchid', 'player1id', 'player2id', 'legs1', 'legs2', 'darts1', 'darts2', '3da1', '3da2', 'dblattempts1', 'dblattempts2', 'highscore1', 'highscore2', 'highco1', 'highco2']);
    fclose($fp);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_set'])) {
        addSet($_POST);
        header('Location: results_entry.php?match=' . $_POST['match_id']);
        exit;
    }
    
    if (isset($_POST['delete_set'])) {
        deleteSet($_POST['set_id']);
        header('Location: results_entry.php?match=' . $_POST['match_id']);
        exit;
    }
    
    if (isset($_POST['update_match'])) {
        updateMatchScore($_POST['match_id']);
        header('Location: results_entry.php?match=' . $_POST['match_id']);
        exit;
    }
}

// Load data
$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

$selected_matchday = isset($_GET['matchday']) ? intval($_GET['matchday']) : null;
$selected_match = isset($_GET['match']) ? intval($_GET['match']) : null;

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

function loadSets($match_id) {
    global $sets_file;
    $sets = [];
    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
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

function getMatchById($match_id) {
    $all_matches = loadMatches();
    foreach ($all_matches as $match) {
        if ($match['id'] == $match_id) return $match;
    }
    return null;
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

function getNextSetId() {
    global $sets_file;
    $max_id = 0;
    if (file_exists($sets_file) && ($fp = fopen($sets_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            if ($row[0] > $max_id) $max_id = $row[0];
        }
        fclose($fp);
    }
    return $max_id + 1;
}

function addSet($data) {
    global $sets_file;
    
    $match = getMatchById($data['match_id']);
     if (!$match) {
        return; // Safety check
    }
    
    // Validate leg counts
    $legs1 = intval($data['legs1']);
    $legs2 = intval($data['legs2']);
    $firsttolegs = intval($match['firsttolegs']);
    
    if ($legs1 > $firsttolegs || $legs2 > $firsttolegs) {
        $_SESSION['error'] = "Legs won cannot exceed first-to-" . $firsttolegs . " format.";
        return;
    }
    
    if ($legs1 != $firsttolegs && $legs2 != $firsttolegs) {
        $_SESSION['error'] = "One player must reach " . $firsttolegs . " legs to win the set.";
        return;
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
        $_SESSION['error'] = "Match is already won (first-to-" . $firsttosets . " sets).";
        return;
    }
    
    $set_id = getNextSetId();
    
    // Calculate 3DA
    $legs1 = intval($data['legs1']);
    $legs2 = intval($data['legs2']);
    $darts1 = intval($data['darts1']);
    $darts2 = intval($data['darts2']);
    
    $da1 = ($legs1 > 0) ? round(($darts1 / $legs1) * 3, 2) : 0;
    $da2 = ($legs2 > 0) ? round(($darts2 / $legs2) * 3, 2) : 0;
    
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

function updateMatchScore($match_id) {
    global $matches_file;
    
    $match = getMatchById($match_id);
     if (!$match) {
        return; // Safety check
    }
    $sets = loadSets($match_id);
    
    // Count sets won by each player
    $sets1 = 0;
    $sets2 = 0;
    
    foreach ($sets as $set) {
        if (intval($set['legs1']) > intval($set['legs2'])) {
            $sets1++;
        } elseif (intval($set['legs2']) > intval($set['legs1'])) {
            $sets2++;
        }
    }
    
    // Update matches.csv
    $all_matches = loadMatches();
    $fp = fopen($matches_file, 'w');
    fputcsv($fp, ['id', 'matchdayid', 'phase', 'firsttosets', 'firsttolegs', 'player1id', 'player2id', 'sets1', 'sets2']);
    
    foreach ($all_matches as $m) {
        if ($m['id'] == $match_id) {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $sets1, $sets2]);
        } else {
            fputcsv($fp, [$m['id'], $m['matchdayid'], $m['phase'], $m['firsttosets'], $m['firsttolegs'], $m['player1id'], $m['player2id'], $m['sets1'], $m['sets2']]);
        }
    }
    fclose($fp);
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
    <title>Results Entry</title>
    <link rel="stylesheet" href="styles.css">
    <!--<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        input[type="number"] { width: 80px; padding: 5px; }
        input[type="submit"], button { padding: 8px 15px; margin: 5px; }
        select { padding: 5px; margin: 5px 0; }
        .info { background-color: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .warning { background-color: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .section { margin: 30px 0; padding: 15px; border: 1px solid #ddd; }
        .set-row { background-color: #f9f9f9; }
        .match-header { background-color: #e8f5e9; font-weight: bold; }
    </style>-->
</head>
<body>
    <h1>Results Entry</h1>
    
    <?php if (empty($matchdays)): ?>
        <div class="warning">
            No tournament created yet. Please run the tournament setup first.<br>
            <a href="matchday_setup.php"><button type="button">Go to Tournament Setup</button></a>
        </div>
    <?php else: ?>
        
        <!-- Step 1: Select Matchday -->
        <?php if (!$selected_matchday): ?>
            <h2>Select Matchday</h2>
            <table>
                <tr>
                    <th>Matchday</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($matchdays as $md): ?>
                <tr>
                    <td>Matchday <?php echo $md['id']; ?></td>
                    <td><?php echo $md['date'] ? $md['date'] : '<em>Not set</em>'; ?></td>
                    <td><?php echo $md['location'] ? htmlspecialchars($md['location']) : '<em>Not set</em>'; ?></td>
                    <td><a href="results_entry.php?matchday=<?php echo $md['id']; ?>"><button>Enter Results</button></a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            
        <!-- Step 2: Select Match -->
        <?php elseif (!$selected_match): ?>
            <?php
            $matchday_matches = array_filter($all_matches, function($m) use ($selected_matchday) {
                return $m['matchdayid'] == $selected_matchday;
            });
            ?>
            
            <h2>Matchday <?php echo $selected_matchday; ?> - Select Match</h2>
            <p><a href="results_entry.php">← Back to Matchday Selection</a></p>
            
            <?php if (empty($matchday_matches)): ?>
                <p>No matches found for this matchday.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Match #</th>
                        <th>Phase</th>
                        <th>Player 1</th>
                        <th>Player 2</th>
                        <th>Score</th>
                        <th>Action</th>
                    </tr>
                    <?php foreach ($matchday_matches as $match): ?>
                    <tr>
                        <td><?php echo $match['id']; ?></td>
                        <td><?php echo getPhaseLabel($match['phase']); ?></td>
                        <td><?php echo getPlayerName($match['player1id']); ?></td>
                        <td><?php echo getPlayerName($match['player2id']); ?></td>
                        <td><?php echo $match['sets1']; ?> : <?php echo $match['sets2']; ?></td>
                        <td><a href="results_entry.php?matchday=<?php echo $selected_matchday; ?>&match=<?php echo $match['id']; ?>"><button>Enter/Edit Results</button></a></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            
        <!-- Step 3: Enter Set Results -->
        <?php else: ?>
            <?php
            $match = getMatchById($selected_match);
            $sets = loadSets($selected_match);
            
            if (!$match) {
                echo '<p>Match not found.</p>';
            } elseif ($match['player1id'] == 0 || $match['player2id'] == 0) {
                echo '<div class="warning">Players not assigned to this match yet. Please assign players first in <a href="matchdays.php?view=' . $selected_matchday . '">Matchday Management</a>.</div>';
            } else {
            ?>
            
            <h2>Match #<?php echo $match['id']; ?> - <?php echo getPhaseLabel($match['phase']); ?></h2>
            <p><a href="results_entry.php?matchday=<?php echo $selected_matchday; ?>">← Back to Match Selection</a></p>
            
            <div class="info">
                <strong><?php echo getPlayerName($match['player1id']); ?></strong> vs <strong><?php echo getPlayerName($match['player2id']); ?></strong><br>
                Format: First to <?php echo $match['firsttosets']; ?> sets (each set first to <?php echo $match['firsttolegs']; ?> legs)<br>
                Current Score: <?php echo $match['sets1']; ?> : <?php echo $match['sets2']; ?>
            </div>
            
            <!-- Existing Sets -->
            <?php if (!empty($sets)): ?>
                <h3>Entered Sets</h3>
                <table>
                    <tr>
                        <th>Set #</th>
                        <th>Player</th>
                        <th>Legs</th>
                        <th>Darts</th>
                        <th>3DA</th>
                        <th>Dbl Att.</th>
                        <th>High Score</th>
                        <th>High CO</th>
                        <th>Action</th>
                    </tr>
                    <?php 
                    $set_num = 1;
                    foreach ($sets as $set): 
                    ?>
                    <tr class="set-row">
                        <td rowspan="2"><strong>Set <?php echo $set_num; ?></strong></td>
                        <td><?php echo getPlayerName($match['player1id']); ?></td>
                        <td><?php echo $set['legs1']; ?></td>
                        <td><?php echo $set['darts1']; ?></td>
                        <td><?php echo $set['3da1']; ?></td>
                        <td><?php echo $set['dblattempts1']; ?></td>
                        <td><?php echo $set['highscore1']; ?></td>
                        <td><?php echo $set['highco1']; ?></td>
                        <td rowspan="2">
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this set?');">
                                <input type="hidden" name="set_id" value="<?php echo $set['id']; ?>">
                                <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                <input type="submit" name="delete_set" value="Delete">
                            </form>
                        </td>
                    </tr>
                    <tr class="set-row">
                        <td><?php echo getPlayerName($match['player2id']); ?></td>
                        <td><?php echo $set['legs2']; ?></td>
                        <td><?php echo $set['darts2']; ?></td>
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
                
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                    <input type="submit" name="update_match" value="Recalculate Match Score">
                </form>
            <?php endif; ?>
            
            <!-- Add New Set -->
            <h3>Add New Set</h3>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="warning"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="info"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                
                <table>
                    <tr>
                        <th>Player</th>
                        <th>Legs Won</th>
                        <th>Darts Thrown</th>
                        <th>Double Attempts</th>
                        <th>High Score</th>
                        <th>Highest Checkout</th>
                    </tr>
                    <tr>
                        <td><?php echo getPlayerName($match['player1id']); ?></td>
                        <td><input type="number" name="legs1" min="0" value="0" required></td>
                        <td><input type="number" name="darts1" min="0" value="0" required></td>
                        <td><input type="number" name="dblattempts1" min="0" value="0" required></td>
                        <td><input type="number" name="highscore1" min="0" max="180" value="0" required></td>
                        <td><input type="number" name="highco1" min="0" max="170" value="0" required></td>
                    </tr>
                    <tr>
                        <td><?php echo getPlayerName($match['player2id']); ?></td>
                        <td><input type="number" name="legs2" min="0" value="0" required></td>
                        <td><input type="number" name="darts2" min="0" value="0" required></td>
                        <td><input type="number" name="dblattempts2" min="0" value="0" required></td>
                        <td><input type="number" name="highscore2" min="0" max="180" value="0" required></td>
                        <td><input type="number" name="highco2" min="0" max="170" value="0" required></td>
                    </tr>
                </table>
                
                <input type="submit" name="add_set" value="Add Set">
            </form>
            
            <?php } ?>
        <?php endif; ?>
    <?php endif; ?>
    
    <p><a href="index.php">Home</a> | <a href="matchdays.php">Matchday Management</a></p>
</body>
</html>
