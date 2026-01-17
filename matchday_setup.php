<?php
// matchday_setup.php - Tournament and Matchday Setup

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

// Handle form submission
$setup_complete = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_tournament'])) {
        generateTournament($_POST);
        $setup_complete = true;
    }
}

// Load data
$players = loadPlayers();

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

function generateTournament($config) {
    global $matchdays_file, $matches_file;
    
    $players = loadPlayers();
    
    $num_regular_matchdays = intval($config['num_matchdays']);
    $has_special = isset($config['has_special']) && $config['has_special'] == '1';
    
    // Create matchdays
    $fp = fopen($matchdays_file, 'w');
    fputcsv($fp, ['id', 'date', 'location']);
    $total_matchdays = $has_special ? $num_regular_matchdays + 1 : $num_regular_matchdays;
    for ($i = 1; $i <= $total_matchdays; $i++) {
        fputcsv($fp, [$i, '', '']);
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
            $config['regular_has_playoffs'],
            $config['regular_playoff_sets'],
            $config['regular_playoff_legs'],
            isset($config['regular_has_third']) && $config['regular_has_third'] == '1'
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
            $config['special_has_playoffs'],
            $config['special_playoff_sets'],
            $config['special_playoff_legs'],
            isset($config['special_has_third']) && $config['special_has_third'] == '1'
        );
    }
    
    fclose($fp);
}

function generateMatchdayMatches($fp, $match_id, $day, $players, $group_rounds, $group_sets, $group_legs, $has_playoffs, $playoff_sets, $playoff_legs, $has_third) {
    
    // Generate group phase matches
    for ($round = 1; $round <= intval($group_rounds); $round++) {
        for ($i = 0; $i < count($players); $i++) {
            for ($j = $i + 1; $j < count($players); $j++) {
                fputcsv($fp, [
                    $match_id++,
                    $day,
                    'group',
                    $group_sets,
                    $group_legs,
                    $players[$i]['id'],
                    $players[$j]['id'],
                    0,
                    0
                ]);
            }
        }
    }
    
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tournament Setup - Darts League</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-section { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; }
        .subsection { margin: 20px 0; padding: 10px; background-color: #f9f9f9; }
        input[type="number"], select { padding: 5px; margin: 5px 0; width: 80px; }
        input[type="submit"], button { padding: 8px 15px; margin: 10px 5px 10px 0; }
        input[type="checkbox"] { margin: 5px; }
        .info { background-color: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .warning { background-color: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        label { display: block; margin: 10px 0 5px 0; }
        label.inline { display: inline; margin: 0 10px 0 0; }
        h3 { margin-top: 20px; }
    </style>
    <script>
        function togglePlayoffs(prefix) {
            var checkbox = document.getElementById(prefix + '_has_playoffs');
            var playoffSettings = document.getElementById(prefix + '_playoff_settings');
            playoffSettings.style.display = checkbox.checked ? 'block' : 'none';
        }
        
        function toggleSpecial() {
            var checkbox = document.getElementById('has_special');
            var specialSettings = document.getElementById('special_settings');
            specialSettings.style.display = checkbox.checked ? 'block' : 'none';
        }
    </script>
</head>
<body>
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
                <strong>Warning:</strong> You need at least 4 players for playoffs. Currently registered: <?php echo count($players); ?> players.<br>
                <a href="players.php"><button type="button">Add More Players</button></a>
            </div>
        <?php else: ?>
            <div class="info">
                <strong>Players registered:</strong> <?php echo count($players); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
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
                    <input type="number" name="regular_group_sets" min="1" value="3" required>
                    
                    <label>Each set - First to X legs:</label>
                    <input type="number" name="regular_group_legs" min="1" value="5" required>
                </div>
                
                <div class="subsection">
                    <h3>Playoff Settings</h3>
                    
                    <label class="inline">
                        <input type="checkbox" id="regular_has_playoffs" name="regular_has_playoffs" value="1" onchange="togglePlayoffs('regular')" checked>
                        Include Top 4 Playoffs (semi-finals + final)
                    </label>
                    
                    <div id="regular_playoff_settings" style="display: block; margin-top: 15px;">
                        <label>Playoff match format - First to X sets:</label>
                        <input type="number" name="regular_playoff_sets" min="1" value="5" required>
                        
                        <label>Each set - First to X legs:</label>
                        <input type="number" name="regular_playoff_legs" min="1" value="5" required>
                        
                        <label class="inline">
                            <input type="checkbox" name="regular_has_third" value="1" checked>
                            Include 3rd place match
                        </label>
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
                        <input type="number" name="special_group_sets" min="1" value="5">
                        
                        <label>Each set - First to X legs:</label>
                        <input type="number" name="special_group_legs" min="1" value="5">
                    </div>
                    
                    <div class="subsection">
                        <h3>Playoff Settings</h3>
                        
                        <label class="inline">
                            <input type="checkbox" id="special_has_playoffs" name="special_has_playoffs" value="1" onchange="togglePlayoffs('special')" checked>
                            Include Top 4 Playoffs
                        </label>
                        
                        <div id="special_playoff_settings" style="display: block; margin-top: 15px;">
                            <label>Playoff match format - First to X sets:</label>
                            <input type="number" name="special_playoff_sets" min="1" value="7">
                            
                            <label>Each set - First to X legs:</label>
                            <input type="number" name="special_playoff_legs" min="1" value="5">
                            
                            <label class="inline">
                                <input type="checkbox" name="special_has_third" value="1" checked>
                                Include 3rd place match
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <input type="submit" name="generate_tournament" value="Generate Tournament" onclick="return confirm('This will create all matchdays and matches. Continue?');">
        </form>
    <?php endif; ?>
    
    <p>
        <a href="players.php">Back to Player Management</a> | 
        <a href="index.php">Home</a>
    </p>
</body>
</html>