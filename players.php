<?php
// players.php - Player and Team Management Interface
session_start();

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Redirect non-admins
if (!$is_admin) {
    header('Location: index.php');
    exit;
}
$csv_file = 'tables/players.csv';

// Initialize CSV file if it doesn't exist
if (!file_exists($csv_file)) {
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname', 'isteam', 'player1id', 'player2id']);
    fclose($fp);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                addPlayer($_POST['name'], $_POST['nickname']);
                break;
            case 'edit':
                editPlayer($_POST['id'], $_POST['name'], $_POST['nickname']);
                break;
            case 'delete':
                deletePlayer($_POST['id']);
                break;
            case 'add_team':
                $result = addTeam($_POST['player1_id'], $_POST['player2_id'], $_POST['team_name'], $_POST['team_nickname']);
                if (!$result['success']) {
                    $team_error = $result['message'];
                }
                break;
            case 'edit_team':
                editTeam($_POST['id'], $_POST['team_name'], $_POST['team_nickname']);
                break;
            case 'delete_team':
                deleteTeam($_POST['id']);
                break;
        }
        // Only redirect if no errors were set
        if (!isset($team_error) && !isset($_SESSION['team_error']) && !isset($_SESSION['delete_error'])) {
            header('Location: players.php');
            exit;
        }
    }
}

// Get player for editing
$edit_player = null;
if (isset($_GET['edit'])) {
    $edit_player = getPlayerById($_GET['edit']);
}

// Get team for editing
$edit_team = null;
if (isset($_GET['edit_team'])) {
    $edit_team = getTeamById($_GET['edit_team']);
}

// Load all players
$all_entities = loadPlayers();
$individuals = array_filter($all_entities, function($p) { return $p['isteam'] == 0; });
$teams = array_filter($all_entities, function($p) { return $p['isteam'] == 1; });

// Functions
function loadPlayers() {
    global $csv_file;
    $players = [];
    if (($fp = fopen($csv_file, 'r')) !== false) {
        $header = fgetcsv($fp);
        while (($row = fgetcsv($fp)) !== false) {
            $players[] = [
                'id' => $row[0],
                'name' => $row[1],
                'nickname' => isset($row[2]) ? $row[2] : '',
                'isteam' => isset($row[3]) ? intval($row[3]) : 0,
                'player1id' => isset($row[4]) ? $row[4] : '',
                'player2id' => isset($row[5]) ? $row[5] : ''
            ];
        }
        fclose($fp);
    }
    return $players;
}

function getPlayerById($id) {
    $players = loadPlayers();
    foreach ($players as $player) {
        if ($player['id'] == $id) {
            return $player;
        }
    }
    return null;
}

function getTeamById($id) {
    $players = loadPlayers();
    foreach ($players as $player) {
        if ($player['id'] == $id && $player['isteam'] == 1) {
            return $player;
        }
    }
    return null;
}

function getPlayerName($id) {
    $player = getPlayerById($id);
    return $player ? $player['name'] : 'Unknown';
}

function getNextId() {
    $players = loadPlayers();
    $max_id = 0;
    foreach ($players as $player) {
        if ($player['id'] > $max_id) {
            $max_id = $player['id'];
        }
    }
    return $max_id + 1;
}

function addPlayer($name, $nickname) {
    global $csv_file;
    $id = getNextId();
    $fp = fopen($csv_file, 'a');
    fputcsv($fp, [$id, $name, $nickname, 0, '', '']);
    fclose($fp);
}

function editPlayer($id, $name, $nickname) {
    global $csv_file;
    $players = loadPlayers();
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname', 'isteam', 'player1id', 'player2id']);
    foreach ($players as $player) {
        if ($player['id'] == $id) {
            fputcsv($fp, [$id, $name, $nickname, $player['isteam'], $player['player1id'], $player['player2id']]);
        } else {
            fputcsv($fp, [$player['id'], $player['name'], $player['nickname'], $player['isteam'], $player['player1id'], $player['player2id']]);
        }
    }
    fclose($fp);
}

function deletePlayer($id) {
    global $csv_file;
    $players = loadPlayers();
    
    // Check if player is part of any team
    $player_in_teams = [];
    foreach ($players as $p) {
        if ($p['isteam'] == 1 && ($p['player1id'] == $id || $p['player2id'] == $id)) {
            $player_in_teams[] = $p['name'];
        }
    }
    
    if (!empty($player_in_teams)) {
        // Cannot delete - player is in teams
        // Store error in session and redirect
        $_SESSION['delete_error'] = 'Cannot delete player: they are part of the following teams: ' . implode(', ', $player_in_teams);
        return;
    }
    
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname', 'isteam', 'player1id', 'player2id']);
    foreach ($players as $player) {
        if ($player['id'] != $id) {
            fputcsv($fp, [$player['id'], $player['name'], $player['nickname'], $player['isteam'], $player['player1id'], $player['player2id']]);
        }
    }
    fclose($fp);
}

function addTeam($player1_id, $player2_id, $team_name, $team_nickname) {
    global $csv_file;
    
    // Validation
    if ($player1_id == $player2_id) {
        return ['success' => false, 'message' => 'Cannot create team: both players are the same'];
    }
    
    $players = loadPlayers();
    
    // Check if both are valid individual players
    $p1 = getPlayerById($player1_id);
    $p2 = getPlayerById($player2_id);
    
    if (!$p1 || !$p2) {
        return ['success' => false, 'message' => 'Invalid player selection'];
    }
    
    if ($p1['isteam'] == 1 || $p2['isteam'] == 1) {
        return ['success' => false, 'message' => 'Cannot create team from teams'];
    }
    
    // Check if team already exists (in either order)
    foreach ($players as $p) {
        if ($p['isteam'] == 1) {
            if (($p['player1id'] == $player1_id && $p['player2id'] == $player2_id) ||
                ($p['player1id'] == $player2_id && $p['player2id'] == $player1_id)) {
                return ['success' => false, 'message' => 'This team already exists'];
            }
        }
    }
    
    // Use provided name and nickname (trim whitespace)
    $team_name = trim($team_name);
    $team_nickname = trim($team_nickname);
    
    // Validate name is not empty
    if (empty($team_name)) {
        return ['success' => false, 'message' => 'Team name cannot be empty'];
    }
    
    $id = getNextId();
    $fp = fopen($csv_file, 'a');
    fputcsv($fp, [$id, $team_name, $team_nickname, 1, $player1_id, $player2_id]);
    fclose($fp);
    
    return ['success' => true];
}

function editTeam($id, $team_name, $team_nickname) {
    global $csv_file;
    $players = loadPlayers();
    
    // Validate name is not empty
    $team_name = trim($team_name);
    if (empty($team_name)) {
        $_SESSION['team_error'] = 'Team name cannot be empty';
        return;
    }
    
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname', 'isteam', 'player1id', 'player2id']);
    foreach ($players as $player) {
        if ($player['id'] == $id && $player['isteam'] == 1) {
            fputcsv($fp, [$id, $team_name, trim($team_nickname), 1, $player['player1id'], $player['player2id']]);
        } else {
            fputcsv($fp, [$player['id'], $player['name'], $player['nickname'], $player['isteam'], $player['player1id'], $player['player2id']]);
        }
    }
    fclose($fp);
}

function deleteTeam($id) {
    global $csv_file;
    $players = loadPlayers();
    $fp = fopen($csv_file, 'w');
    fputcsv($fp, ['id', 'name', 'nickname', 'isteam', 'player1id', 'player2id']);
    foreach ($players as $player) {
        if ($player['id'] != $id) {
            fputcsv($fp, [$player['id'], $player['name'], $player['nickname'], $player['isteam'], $player['player1id'], $player['player2id']]);
        }
    }
    fclose($fp);
}

// Load matchdays for navigation
$matchdays = [];
$matchdays_file = 'tables/matchdays.csv';
if (file_exists($matchdays_file) && ($fp = fopen($matchdays_file, 'r')) !== false) {
    $header = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        $matchdays[] = ['id' => $row[0]];
    }
    fclose($fp);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Player Management</title>
    <link rel="stylesheet" href="styles.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
    
    <nav>
        <a href="index.php">Tournament Overview</a>
        <?php if (!empty($matchdays)): ?>
            <?php foreach ($matchdays as $md): ?>
                | <a href="matchdays.php?view=<?php echo $md['id']; ?>">Matchday <?php echo $md['id']; ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    
    <h1>Player & Team Management</h1>
    
    <?php if (isset($_SESSION['delete_error'])): ?>
        <div class="error">
            <?php echo $_SESSION['delete_error']; unset($_SESSION['delete_error']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['team_error'])): ?>
        <div class="error">
            <?php echo $_SESSION['team_error']; unset($_SESSION['team_error']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($team_error)): ?>
        <div class="error">
            <?php echo $team_error; ?>
        </div>
    <?php endif; ?>
    
    <!-- INDIVIDUAL PLAYERS SECTION -->
    <div class="form-section">
        <h2><?php echo $edit_player ? 'Edit Player' : 'Add New Player'; ?></h2>
        <form method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_player ? 'edit' : 'add'; ?>">
            <?php if ($edit_player): ?>
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_player['id']); ?>">
            <?php endif; ?>
            
            <label>Name: <input type="text" name="name" required value="<?php echo $edit_player ? htmlspecialchars($edit_player['name']) : ''; ?>"></label><br>
            <label>Nickname: <input type="text" name="nickname" value="<?php echo $edit_player ? htmlspecialchars($edit_player['nickname']) : ''; ?>"></label><br>
            
            <input type="submit" value="<?php echo $edit_player ? 'Update Player' : 'Add Player'; ?>">
            <?php if ($edit_player): ?>
                <a href="players.php"><button type="button">Cancel</button></a>
            <?php endif; ?>
        </form>
    </div>

    <h2>Individual Players</h2>
    <?php if (empty($individuals)): ?>
        <p>No players added yet.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Nickname</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($individuals as $player): ?>
                <tr>
                    <td><?php echo htmlspecialchars($player['id']); ?></td>
                    <td><?php echo htmlspecialchars($player['name']); ?></td>
                    <td><?php echo htmlspecialchars($player['nickname']); ?></td>
                    <td>
                        <a href="players.php?edit=<?php echo $player['id']; ?>"><button>Edit</button></a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this player?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $player['id']; ?>">
                            <input type="submit" value="Delete">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    
    <hr style="margin: 40px 0;">
    
    <!-- TEAMS SECTION -->
    <div class="form-section">
        <h2><?php echo $edit_team ? 'Edit Team' : 'Create New Team'; ?></h2>
        <?php if (count($individuals) < 2 && !$edit_team): ?>
            <p class="warning">You need at least 2 individual players to create a team.</p>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $edit_team ? 'edit_team' : 'add_team'; ?>">
                <?php if ($edit_team): ?>
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($edit_team['id']); ?>">
                <?php endif; ?>
                
                <?php if (!$edit_team): ?>
                    <label>Player 1:
                        <select name="player1_id" id="player1_select" required onchange="updateTeamName()">
                            <option value="">-- Select Player --</option>
                            <?php foreach ($individuals as $player): ?>
                                <option value="<?php echo $player['id']; ?>" data-name="<?php echo htmlspecialchars($player['name']); ?>">
                                    <?php echo htmlspecialchars($player['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label><br>
                    
                    <label>Player 2:
                        <select name="player2_id" id="player2_select" required onchange="updateTeamName()">
                            <option value="">-- Select Player --</option>
                            <?php foreach ($individuals as $player): ?>
                                <option value="<?php echo $player['id']; ?>" data-name="<?php echo htmlspecialchars($player['name']); ?>">
                                    <?php echo htmlspecialchars($player['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label><br>
                <?php else: ?>
                    <p><strong>Players:</strong> <?php echo getPlayerName($edit_team['player1id']); ?> + <?php echo getPlayerName($edit_team['player2id']); ?></p>
                <?php endif; ?>
                
                <label>Team Name:
                    <input type="text" name="team_name" id="team_name" required value="<?php echo $edit_team ? htmlspecialchars($edit_team['name']) : ''; ?>" style="width: 300px;">
                </label><br>
                
                <label>Team Nickname:
                    <input type="text" name="team_nickname" value="<?php echo $edit_team ? htmlspecialchars($edit_team['nickname']) : ''; ?>" style="width: 150px;">
                </label><br>
                
                <input type="submit" value="<?php echo $edit_team ? 'Update Team' : 'Create Team'; ?>">
                <?php if ($edit_team): ?>
                    <a href="players.php"><button type="button">Cancel</button></a>
                <?php endif; ?>
            </form>
            
            <?php if (!$edit_team): ?>
                <script>
                function updateTeamName() {
                    var select1 = document.getElementById('player1_select');
                    var select2 = document.getElementById('player2_select');
                    var teamNameInput = document.getElementById('team_name');
                    
                    var player1 = select1.options[select1.selectedIndex];
                    var player2 = select2.options[select2.selectedIndex];
                    
                    if (player1.value && player2.value) {
                        var name1 = player1.getAttribute('data-name');
                        var name2 = player2.getAttribute('data-name');
                        teamNameInput.value = name1 + ' & ' + name2;
                    }
                }
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <h2>Teams</h2>
    <?php if (empty($teams)): ?>
        <p>No teams created yet.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Team Name</th>
                <th>Nickname</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($teams as $team): ?>
                <tr>
                    <td><?php echo htmlspecialchars($team['id']); ?></td>
                    <td><?php echo htmlspecialchars($team['name']); ?></td>
                    <td><?php echo htmlspecialchars($team['nickname']); ?></td>
                    <td>
                        <a href="players.php?edit_team=<?php echo $team['id']; ?>"><button>Edit</button></a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this team?');">
                            <input type="hidden" name="action" value="delete_team">
                            <input type="hidden" name="id" value="<?php echo $team['id']; ?>">
                            <input type="submit" value="Delete">
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
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
    </p>
</body>
</html>
