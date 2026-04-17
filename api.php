<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Configuration - Integrated with Shared Hosting Credentials
$db_host = 'localhost';
$db_user = 'alilogis_ratnaproperty'; 
$db_pass = 'EPNZcQsrWGvFEWkHYt2F';     
$db_name = 'alilogis_ratnaproperty';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(["error" => "Database Connection Failed: " . $conn->connect_error]));
}

$action = $_GET['action'] ?? '';

// 1. GET ALL DATA
if ($action == 'get_data') {
    // Settings
    $settings = [];
    $res = $conn->query("SELECT * FROM site_settings");
    while($row = $res->fetch_assoc()) {
        $settings[$row['key_name']] = $row['value_text'];
    }

    // Catalog
    $catalog = [];
    $res = $conn->query("SELECT * FROM catalog");
    if ($res) {
        while($row = $res->fetch_assoc()) {
            $row['tags'] = json_decode($row['tags'], true) ?: [];
            $row['specs'] = json_decode($row['specs'], true) ?: (object)[];
            $catalog[] = $row;
        }
    }

    // Testimonials
    $testimonials = [];
    $res = $conn->query("SELECT * FROM testimonials");
    while($row = $res->fetch_assoc()) {
        $testimonials[] = $row;
    }

    echo json_encode([
        "profile" => [
            "name" => $settings['site_name'],
            "whatsapp" => $settings['whatsapp'],
            "address" => $settings['address'],
            "maps" => $settings['maps'],
            "instagram" => $settings['instagram']
        ],
        "slider" => [
            "speed" => (int)$settings['slider_speed'],
            "effect" => $settings['slider_effect']
        ],
        "catalog" => $catalog,
        "testimonials" => $testimonials
    ]);
}

// 2. AUTHENTICATION
if ($action == 'login') {
    $data = json_decode(file_get_contents("php://input"), true);
    $pass = $data['password'] ?? '';
    
    $res = $conn->query("SELECT value_text FROM site_settings WHERE key_name = 'admin_password'");
    $row = $res->fetch_assoc();
    
    if ($pass === $row['value_text']) {
        echo json_encode(["success" => true]);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Invalid Password"]);
    }
}

// 3. SAVE DATA (PROTECTED)
if ($action == 'save_data') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Simple protection - check header or query string
    $pass = $_SERVER['HTTP_X_ADMIN_PASS'] ?? $_GET['pass'] ?? '';
    
    // Check if table exists first
    $tableCheck = $conn->query("SHOW TABLES LIKE 'site_settings'");
    if($tableCheck->num_rows == 0) {
        http_response_code(500);
        die(json_encode(["error" => "Database tables missing. Please import database.sql first."]));
    }

    $res = $conn->query("SELECT value_text FROM site_settings WHERE key_name = 'admin_password'");
    $row = $res->fetch_assoc();
    
    if(!$row || $pass !== $row['value_text']) {
        http_response_code(403);
        die(json_encode(["error" => "Unauthorized. Invalid Password."]));
    }

    $errors = [];

    // Update Settings (using REPLACE for upsert)
    if(isset($data['profile'])) {
        $stmt = $conn->prepare("REPLACE INTO site_settings (value_text, key_name) VALUES (?, ?)");
        $items = [
            'site_name' => $data['profile']['name'] ?? '',
            'whatsapp' => $data['profile']['whatsapp'] ?? '',
            'address' => $data['profile']['address'] ?? '',
            'maps' => $data['profile']['maps'] ?? '',
            'instagram' => $data['profile']['instagram'] ?? '',
            'slider_speed' => (string)($data['slider']['speed'] ?? '800'),
            'slider_effect' => $data['slider']['effect'] ?? 'slide'
        ];
        foreach($items as $k => $v) {
            $stmt->bind_param("ss", $v, $k);
            if(!$stmt->execute()) $errors[] = "Setting $k failed: " . $stmt->error;
        }
    }

    // Rebuild Catalog
    if(isset($data['catalog'])) {
        $conn->query("DELETE FROM catalog");
        $stmt = $conn->prepare("INSERT INTO catalog (title, price, location, image, tags, badge, badgeColor, specs) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach($data['catalog'] as $item) {
            $tags_json = is_string($item['tags']) ? $item['tags'] : json_encode($item['tags'] ?: []);
            $specs_json = is_string($item['specs']) ? $item['specs'] : json_encode($item['specs'] ?: (object)[]);
            $stmt->bind_param("ssssssss", $item['title'], $item['price'], $item['location'], $item['image'], $tags_json, $item['badge'], $item['badgeColor'], $specs_json);
            if(!$stmt->execute()) $errors[] = "Catalog item failed: " . $stmt->error;
        }
    }

    // Rebuild Testimonials
    if(isset($data['testimonials'])) {
        $conn->query("DELETE FROM testimonials");
        $stmt = $conn->prepare("INSERT INTO testimonials (name, role, image, text) VALUES (?, ?, ?, ?)");
        foreach($data['testimonials'] as $item) {
            $stmt->bind_param("ssss", $item['name'], $item['role'], $item['image'], $item['text']);
            if(!$stmt->execute()) $errors[] = "Testimonial item failed: " . $stmt->error;
        }
    }

    echo json_encode([
        "success" => count($errors) === 0,
        "errors" => $errors
    ]);
}

$conn->close();
?>