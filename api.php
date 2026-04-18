<?php
// api.php - Complete API for Deep Soul Community Website
// Place this file in the same directory as your website HTML

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ==================== CONFIGURATION ====================
// Database Configuration (RocketNode)
define('DB_HOST', '51.79.149.22');
define('DB_USER', 'u224984_cghr9WzOpa');
define('DB_PASS', 'ZsmwgqdM+Gd6bQqt^=Fo=c0!'); // ⚠️ CHANGE THIS TO YOUR REAL PASSWORD
define('DB_NAME', 'u224984_cghr9WzOpa');

// Discord OAuth Configuration
// Get these from https://discord.com/developers/applications
define('DISCORD_CLIENT_ID', '1495205328394911796');     // Replace with your Discord App Client ID
define('DISCORD_CLIENT_SECRET', 'Fv08maHzkXHpEdR_8K_SONysjC9jGZpb'); // Replace with your Discord App Client Secret
define('DISCORD_REDIRECT_URI', 'https://arosh123-we.github.io/web/'); // Replace with your actual website URL

// ==================== DATABASE CONNECTION ====================
function getDatabaseConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// ==================== DISCORD OAuth FUNCTIONS ====================
function exchangeDiscordCode($code) {
    $token_url = 'https://discord.com/api/oauth2/token';
    
    $data = [
        'client_id' => DISCORD_CLIENT_ID,
        'client_secret' => DISCORD_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => DISCORD_REDIRECT_URI
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $tokenData = json_decode($response, true);
    return $tokenData['access_token'] ?? null;
}

function getDiscordUser($accessToken) {
    $user_url = 'https://discord.com/api/users/@me';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $user_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// ==================== DATABASE FUNCTIONS ====================
function getPlayerByDiscordId($conn, $discordId) {
    $stmt = $conn->prepare("SELECT * FROM player_characters WHERE discord_id = ?");
    $stmt->bind_param("s", $discordId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getAllPlayers($conn, $limit = 100) {
    $stmt = $conn->prepare("SELECT discord_id, character_name, money, bank, job, playtime, whitelisted, last_updated FROM player_characters ORDER BY money DESC LIMIT ?");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $players = [];
    while ($row = $result->fetch_assoc()) {
        $players[] = $row;
    }
    return $players;
}

function getLeaderboard($conn, $limit = 20) {
    $stmt = $conn->prepare("SELECT character_name, money, bank, job FROM player_characters ORDER BY money DESC LIMIT ?");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $leaderboard = [];
    $rank = 1;
    while ($row = $result->fetch_assoc()) {
        $row['rank'] = $rank++;
        $leaderboard[] = $row;
    }
    return $leaderboard;
}

function getServerStats($conn) {
    $stats = [];
    
    // Total players
    $result = $conn->query("SELECT COUNT(*) as total FROM player_characters");
    $stats['total_players'] = $result->fetch_assoc()['total'];
    
    // Total money in economy
    $result = $conn->query("SELECT SUM(money + bank) as total_money FROM player_characters");
    $stats['total_economy'] = $result->fetch_assoc()['total_money'] ?? 0;
    
    // Average money per player
    $result = $conn->query("SELECT AVG(money) as avg_money FROM player_characters");
    $stats['average_money'] = round($result->fetch_assoc()['avg_money'] ?? 0);
    
    // Job distribution
    $result = $conn->query("SELECT job, COUNT(*) as count FROM player_characters GROUP BY job ORDER BY count DESC LIMIT 5");
    $stats['top_jobs'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['top_jobs'][] = $row;
    }
    
    // Whitelisted count
    $result = $conn->query("SELECT COUNT(*) as whitelisted FROM player_characters WHERE whitelisted = 1");
    $stats['whitelisted_count'] = $result->fetch_assoc()['whitelisted'];
    
    return $stats;
}

function submitApplication($conn, $data) {
    $stmt = $conn->prepare("
        INSERT INTO applications (discord_id, type, full_name, age, experience, reason, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("sssiss", 
        $data['discord_id'], 
        $data['type'], 
        $data['full_name'], 
        $data['age'], 
        $data['experience'], 
        $data['reason']
    );
    
    if ($stmt->execute()) {
        return ['success' => true, 'id' => $stmt->insert_id];
    }
    return ['success' => false, 'error' => $stmt->error];
}

function getUserApplications($conn, $discordId) {
    $stmt = $conn->prepare("SELECT * FROM applications WHERE discord_id = ? ORDER BY submitted_at DESC");
    $stmt->bind_param("s", $discordId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $applications = [];
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
    return $applications;
}

function updatePlayerStats($conn, $discordId, $money, $bank, $job) {
    $stmt = $conn->prepare("
        UPDATE player_characters 
        SET money = ?, bank = ?, job = ?, last_updated = NOW() 
        WHERE discord_id = ?
    ");
    $stmt->bind_param("iiss", $money, $bank, $job, $discordId);
    return $stmt->execute();
}

// ==================== API ROUTING ====================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$conn = getDatabaseConnection();

switch ($action) {
    // Discord OAuth Login
    case 'discordLogin':
        if (isset($_GET['code'])) {
            $code = $_GET['code'];
            $accessToken = exchangeDiscordCode($code);
            
            if ($accessToken) {
                $user = getDiscordUser($accessToken);
                if ($user && isset($user['id'])) {
                    // Check if user exists in database, if not create
                    $existingPlayer = getPlayerByDiscordId($conn, $user['id']);
                    
                    if (!$existingPlayer) {
                        // Create new player entry
                        $stmt = $conn->prepare("
                            INSERT INTO player_characters (discord_id, character_name, whitelisted) 
                            VALUES (?, ?, 0)
                        ");
                        $stmt->bind_param("ss", $user['id'], $user['username']);
                        $stmt->execute();
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'avatar' => $user['avatar'],
                        'email' => $user['email'] ?? null
                    ]);
                } else {
                    http_response_code(401);
                    echo json_encode(['error' => 'Failed to get user info']);
                }
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid authorization code']);
            }
        } else {
            // Return Discord OAuth URL for client-side redirect
            $oauthUrl = 'https://discord.com/api/oauth2/authorize?' . http_build_query([
                'client_id' => DISCORD_CLIENT_ID,
                'redirect_uri' => DISCORD_REDIRECT_URI,
                'response_type' => 'code',
                'scope' => 'identify email'
            ]);
            echo json_encode(['oauth_url' => $oauthUrl]);
        }
        break;
    
    // Get player by Discord ID
    case 'getPlayer':
        if (isset($_GET['discord_id'])) {
            $player = getPlayerByDiscordId($conn, $_GET['discord_id']);
            echo json_encode($player ?: null);
        } else {
            echo json_encode(['error' => 'discord_id parameter required']);
        }
        break;
    
    // Get all players
    case 'getAllPlayers':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
        $players = getAllPlayers($conn, $limit);
        echo json_encode($players);
        break;
    
    // Get leaderboard
    case 'getLeaderboard':
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $leaderboard = getLeaderboard($conn, $limit);
        echo json_encode($leaderboard);
        break;
    
    // Get server statistics
    case 'getServerStats':
        $stats = getServerStats($conn);
        echo json_encode($stats);
        break;
    
    // Submit an application
    case 'submitApplication':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input) {
                $result = submitApplication($conn, $input);
                echo json_encode($result);
            } else {
                echo json_encode(['error' => 'Invalid JSON data']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;
    
    // Get user applications
    case 'getApplications':
        if (isset($_GET['discord_id'])) {
            $applications = getUserApplications($conn, $_GET['discord_id']);
            echo json_encode($applications);
        } else {
            echo json_encode(['error' => 'discord_id parameter required']);
        }
        break;
    
    // Update player stats (called from FiveM server)
    case 'updatePlayer':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            if ($input && isset($input['discord_id'])) {
                $result = updatePlayerStats(
                    $conn,
                    $input['discord_id'],
                    $input['money'] ?? 0,
                    $input['bank'] ?? 0,
                    $input['job'] ?? 'Unemployed'
                );
                echo json_encode(['success' => $result]);
            } else {
                echo json_encode(['error' => 'Invalid data']);
            }
        } else {
            echo json_encode(['error' => 'POST method required']);
        }
        break;
    
    // Health check endpoint
    case 'health':
        echo json_encode([
            'status' => 'online',
            'timestamp' => date('Y-m-d H:i:s'),
            'database' => $conn->ping() ? 'connected' : 'disconnected'
        ]);
        break;
    
    // Default - show available endpoints
    default:
        echo json_encode([
            'api' => 'Deep Soul Community API',
            'version' => '1.0.0',
            'endpoints' => [
                'discordLogin' => 'GET - Discord OAuth login',
                'getPlayer?discord_id=ID' => 'GET - Get player by Discord ID',
                'getAllPlayers?limit=100' => 'GET - Get all players',
                'getLeaderboard?limit=20' => 'GET - Get money leaderboard',
                'getServerStats' => 'GET - Get server statistics',
                'submitApplication' => 'POST - Submit an application',
                'getApplications?discord_id=ID' => 'GET - Get user applications',
                'updatePlayer' => 'POST - Update player stats (FiveM sync)',
                'health' => 'GET - API health check'
            ]
        ]);
        break;
}

$conn->close();
?>