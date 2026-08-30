<?php 

/**
 * Validation functions with strict error throwing
 */

function validateInt($value, $min = null, $max = null): int {
    if (!isset($value)) {
        throw new InvalidArgumentException("Value is required");
    }

    $options = [];
    if ($min !== null) $options['min_range'] = $min;
    if ($max !== null) $options['max_range'] = $max;

    $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => $options]);
    if ($result === false) {
        throw new InvalidArgumentException("Invalid integer value");
    }
    return $result;
}

function validateString($value, $maxLength = 255, $allowedPattern = '/^[\p{L}\p{N}\s\-\.\']+$/u'): string {
    if (!isset($value)) {
        throw new InvalidArgumentException("value is required.");
    }

    $value = trim(strip_tags($value));

    if (empty($value)) {
        throw new InvalidArgumentException("value cannot be empty.");
    }

    if (mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException("value must not exceed $maxLength characters.");
    }

    if (!preg_match($allowedPattern, $value)) {
        throw new InvalidArgumentException("value contains invalid characters.");
    }

    return $value;
}


function validateEmail($value): string {
    if (!isset($value)) {
        throw new InvalidArgumentException("Email is required");
    }

    $value = trim($value);
    $value = filter_var($value, FILTER_SANITIZE_EMAIL);
    $result = filter_var($value, FILTER_VALIDATE_EMAIL);

    if ($result === false) {
        throw new InvalidArgumentException("Invalid email address");
    }
    return $result;
}

function validatePassword($value, $minLength = 8): string {
    if (!isset($value)) {
        throw new InvalidArgumentException("Password is required");
    }

    if (strlen($value) < $minLength) {
        throw new InvalidArgumentException("Password must be at least $minLength characters");
    }

    return password_hash($value, PASSWORD_DEFAULT);
}

function validateIP($value): string {
    if (!isset($value)) {
        throw new InvalidArgumentException("IP address is required");
    }

    $result = filter_var($value, FILTER_VALIDATE_IP);
    if ($result === false) {
        throw new InvalidArgumentException("Invalid IP address");
    }
    return $result;
}

/**
 * Usage with error handling
 */


function old($key, $default = '') {
    return isset($_SESSION['form_old'][$key])
        ? htmlspecialchars($_SESSION['form_old'][$key], ENT_QUOTES, 'UTF-8')
        : $default;
}




/*  Example usage:
try {
    $params = [
        "user_id" => validateInt($_POST['user_id'] ?? null),
        "reseller_id" => validateInt($_POST['reseller_id'] ?? null),
        "first_name" => validateString($_POST['first_name'] ?? null, 50),
        "last_name" => validateString($_POST['last_name'] ?? null, 50),
        "email" => validateEmail($_POST['email'] ?? null),
        "password" => validatePassword($_POST['password'] ?? null),
        "role" => 'agency',
        "token" => bin2hex(random_bytes(16)),
        "login_ip" => validateIP($_SERVER['REMOTE_ADDR']),
        "created_at" => date('Y-m-d H:i:s'),
        "country_id" => validateInt($_POST['country'] ?? null, 1, 250)
    ];

    // Database insertion example (using PDO)
    $db->insert("users", $params);

    echo "User created successfully!";
    
} catch (InvalidArgumentException $e) {
    // Handle validation errors
    http_response_code(400);
    echo "Validation error: " . $e->getMessage();
    
} catch (PDOException $e) {
    // Handle database errors
    http_response_code(500);
    echo "Database error: " . $e->getMessage();
} */