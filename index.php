<?php
// index.php - Tournament Overview and Entry Point

session_start();

$admin_password = 'darts2026'; // Change this to your desired password

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid password';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Load data
$players_file = 'tables/players.csv';
$matchdays_file = 'tables/matchdays.csv';
$matches_file = 'tables/matches.csv';

$players = loadPlayers();
$matchdays = loadMatchdays();
$all_matches = loadMatches();

// Analyze tournament structure
$tournament_info = analyzeTournament($matchdays, $all_matches);

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

function analyzeTournament($matchdays, $matches) {
    $info = [
        'has_tournament' => !empty($matchdays),
        'num_matchdays' => count($matchdays),
        'formats' => []
    ];
    
    if (empty($matchdays)) {
        return $info;
    }
    
    // Analyze each matchday to determine format
    foreach ($matchdays as $md) {
        $md_matches = array_filter($matches, function($m) use ($md) {
            return $m['matchdayid'] == $md['id'];
        });
        
        $group_matches = array_filter($md_matches, function($m) { return $m['phase'] == 'group'; });
        $playoff_matches = array_filter($md_matches, function($m) { return $m['phase'] != 'group'; });
        
        $format = [
            'matchday_id' => $md['id'],
            'has_group' => !empty($group_matches),
            'has_playoffs' => !empty($playoff_matches),
            'group_format' => null,
            'playoff_format' => null,
            'playoff_types' => []
        ];
        
        if (!empty($group_matches)) {
            $first_match = array_values($group_matches)[0];
            $format['group_format'] = [
                'sets' => $first_match['firsttosets'],
                'legs' => $first_match['firsttolegs']
            ];
        }
        
        if (!empty($playoff_matches)) {
            $first_playoff = array_values($playoff_matches)[0];
            $format['playoff_format'] = [
                'sets' => $first_playoff['firsttosets'],
                'legs' => $first_playoff['firsttolegs']
            ];
            
            foreach ($playoff_matches as $pm) {
                $format['playoff_types'][] = $pm['phase'];
            }
        }
        
        $info['formats'][$md['id']] = $format;
    }
    
    return $info;
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
?>
<!DOCTYPE html>
<html>
<head>
    <title>Tournament Overview</title>
    <link rel="stylesheet" href="styles.css">
    <!--<style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 1200px; }
        h1 { color: #333; }
        .admin-panel { background-color: #f0f0f0; padding: 15px; margin: 20px 0; border-left: 4px solid #333; }
        .login-box { background-color: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ddd; max-width: 400px; }
        .info { background-color: #e7f3ff; padding: 10px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .warning { background-color: #fff3cd; padding: 10px; margin: 10px 0; border-left: 4px solid #ffc107; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        input[type="password"] { padding: 8px; margin: 5px 0; width: 200px; }
        input[type="submit"], button { padding: 8px 15px; margin: 5px; }
        .format-box { background-color: #f9f9f9; padding: 10px; margin: 5px 0; border-left: 3px solid #4CAF50; }
        .section { margin: 30px 0; }
        a { text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>-->
</head>
<body>
    <h1>Tournament Overview</h1>
    
    <!-- Tournament Information -->
    <div class="section">
        <?php if (!$tournament_info['has_tournament']): ?>
            <div class="info">
                <strong>No tournament created yet.</strong><br>
                <?php if ($is_admin): ?>
                    Please use the <a href="matchday_setup.php">Tournament Setup</a> to create your tournament structure.
                <?php else: ?>
                    The tournament has not been set up yet. Please check back later.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <h2>Tournament Information</h2>
            
            <p><strong>Total Matchdays:</strong> <?php echo $tournament_info['num_matchdays']; ?></p>
            <p><strong>Registered Players:</strong> <?php echo count($players); ?></p>
            
            <?php if (!empty($players)): ?>
                <h3>Players</h3>
                <ul>
                    <?php foreach ($players as $player): ?>
                        <li><?php echo getPlayerName($player['id']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <h3>Matchday Formats</h3>
            
            <?php 
            // Group formats by similarity
            $format_groups = [];
            foreach ($tournament_info['formats'] as $md_id => $format) {
                $key = serialize([
                    'group' => $format['group_format'],
                    'playoff' => $format['playoff_format'],
                    'types' => $format['playoff_types']
                ]);
                
                if (!isset($format_groups[$key])) {
                    $format_groups[$key] = [
                        'format' => $format,
                        'matchdays' => []
                    ];
                }
                $format_groups[$key]['matchdays'][] = $md_id;
            }
            ?>
            
            <?php foreach ($format_groups as $group): ?>
                <div class="format-box">
                    <strong>Matchdays: <?php echo implode(', ', $group['matchdays']); ?></strong><br>
                    
                    <?php if ($group['format']['has_group']): ?>
                        <strong>Group Phase:</strong> 
                        First to <?php echo $group['format']['group_format']['sets']; ?> sets 
                        (each set first to <?php echo $group['format']['group_format']['legs']; ?> legs)<br>
                    <?php endif; ?>
                    
                    <?php if ($group['format']['has_playoffs']): ?>
                        <strong>Playoffs:</strong> 
                        First to <?php echo $group['format']['playoff_format']['sets']; ?> sets 
                        (each set first to <?php echo $group['format']['playoff_format']['legs']; ?> legs)<br>
                        <strong>Playoff Structure:</strong> 
                        <?php 
                        $types = array_map(function($t) {
                            $labels = ['semi1' => 'Semi 1', 'semi2' => 'Semi 2', 'third' => '3rd Place', 'final' => 'Final'];
                            return isset($labels[$t]) ? $labels[$t] : $t;
                        }, $group['format']['playoff_types']);
                        echo implode(', ', $types);
                        ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <h3>Matchdays Schedule</h3>
            <table>
                <tr>
                    <th>Matchday</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Structure</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($matchdays as $md): ?>
                <?php $format = $tournament_info['formats'][$md['id']]; ?>
                <tr>
                    <td>Matchday <?php echo $md['id']; ?></td>
                    <td><?php echo $md['date'] ? $md['date'] : '<em>Not scheduled</em>'; ?></td>
                    <td><?php echo $md['location'] ? htmlspecialchars($md['location']) : '<em>TBD</em>'; ?></td>
                    <td>
                        <?php 
                        $parts = [];
                        if ($format['has_group']) $parts[] = 'Group';
                        if ($format['has_playoffs']) $parts[] = 'Playoffs';
                        echo implode(' + ', $parts);
                        ?>
                    </td>
                    <td>
                        <?php if ($is_admin): ?>
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>"><button>View Details</button></a>
                        <?php else: ?>
                            <a href="matchdays.php?view=<?php echo $md['id']; ?>">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
    
    <?php if ($is_admin): ?>
        <!-- Admin Panel -->
        <div class="admin-panel">
            <h3>Management Area</h3>
            <a href="players.php"><button>Player Management</button></a>
            <a href="matchday_setup.php"><button>Tournament Setup</button></a>
            <a href="matchdays.php"><button>Matchday Management</button></a>
            <a href="index.php?logout=1"><button>Logout</button></a>
        </div>
    <?php else: ?>
        <!-- Login Form -->
        <div class="login-box">
            <h3>Management Login</h3>
            <?php if (isset($login_error)): ?>
                <div class="warning"><?php echo $login_error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <label>Password:</label><br>
                <input type="password" name="password" required><br>
                <input type="submit" name="login" value="Login">
            </form>
        </div>
    <?php endif; ?>
    
</body>
</html>
