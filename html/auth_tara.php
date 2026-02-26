<?php


function getUserIpAddr()
{ 
	if(!empty($_SERVER['HTTP_CLIENT_IP']))
	{ 
		$ip = $_SERVER['HTTP_CLIENT_IP']; 
	}
	else
		if(!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
		{
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR']; 
		}
		else
		{
			$ip = $_SERVER['REMOTE_ADDR']; 
		} 
	return $ip; 
}

function verifyQuickLogin($phone, $mpesaCode) {
    global $conn;
    
  
    $phone = filter_var($phone, FILTER_SANITIZE_STRING);
    $mpesaCode = filter_var($mpesaCode, FILTER_SANITIZE_STRING);
    
   
    $stmt = $conn->prepare("SELECT * FROM transactions WHERE phone = ? AND mpesa_code = ? AND status = 'completed' AND used = 0 LIMIT 1");
    $stmt->execute([$phone, $mpesaCode]);
    $transaction = $stmt->fetch();
    
    if (!$transaction) {
        return [
            'status' => false,
            'message' => 'Invalid phone number or M-Pesa receipt code. Please check and try again.'
        ];
    }
    

    $stmt = $conn->prepare("UPDATE transactions SET used = 1, used_at = NOW() WHERE id = ?");
    $stmt->execute([$transaction['id']]);
    
    //better use auto_increment... $sessionId = generateSessionId();
    $planId = $transaction['plan_id'];
    

    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    

    $expiryTime = calculateExpiryTime($plan['type']);
    
    $stmt = $conn->prepare("select id from radcheck where phone = ?");
    $stmt->execute([$phone]);
    if ($plan = $stmt->fetch()) 
	$nUserId = $user["id"];
    else
    {
	$stmt = $conn->prepare("insert into radcheck (username, phone) values (?)");
	$stmt->execute([$phone, $phone]);
	$stmt = $conn->prepare("select last_insert_id() as lastId");
	$stmt->execute([$phone]);
	$plan = $stmt->fetch(); 
	$nUserId = $user["lastId"];
    }

//    $stmt = $conn->prepare("INSERT INTO sessions (session_id, id, phone, transaction_id, plan_id, expires_at, createdTime) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt = $conn->prepare("INSERT INTO session (id, ip, username, transaction_id, plan_id, expires_at, createdTime) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$nUserId, getUserIpAddr(), $phone, $transaction['id'], $planId, $expiryTime]);
    
 
    setcookie('wifi_session', $sessionId, time() + 86400, '/', '', false, true);
    
    return [
        'status' => true,
        'message' => 'Login successful! You are now connected to WiFi.',
        'session_id' => $sessionId,
        'plan' => $plan['name'],
        'expires_at' => $expiryTime
    ];
}

function verifyAccountLogin($email, $password, $remember = false) {
    global $conn;
    
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    

    $stmt = $conn->prepare("SELECT * FROM radcheck WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['value'])) {
        return [
            'status' => false,
            'message' => 'Invalid email or password. Please try again.'
        ];
    }

    $stmt = $conn->prepare("UPDATE radcheck SET last_login = NOW() WHERE id = ?");
//    $stmt = $conn->prepare("select id from radcheck WHERE id = ?") or die(mysql_error()); 
    $stmt->execute([$user['id']]);
  
    $ip = getUserIpAddr();
    $stmt = $conn->prepare("INSERT INTO session (id, ip, username, active) VALUES (?, ?, ?, 1)");
    $stmt->execute([$user['id'], $ip, $user['email']]);
      
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['phone'] = $user['phone'];

    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (86400 * 30); // 30 days
        
        $stmt = $conn->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))");
        $stmt->execute([$user['id'], $token, $expires]);
        
        setcookie('remember_token', $token, $expires, '/', '', false, true);
    }

   return [
        'status' => true,
        'message' => 'Login successful!',
        'user_id' => $user['id'],
        'name' => $user['name']
    ];
}


function registerUser($name, $email, $phone, $password, $confirmPassword) {
    global $conn;
    

    $name = filter_var($name, FILTER_SANITIZE_STRING);
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    $phone = filter_var($phone, FILTER_SANITIZE_STRING);
    

    if ($password !== $confirmPassword) {
        return [
            'status' => false,
            'message' => 'Passwords do not match.'
        ];
    }
    

//    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt = $conn->prepare("SELECT id FROM radcheck WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [
            'status' => false,
            'message' => 'Email already in use. Please use a different email or login to your existing account.'
        ];
    }

//    $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
    $stmt = $conn->prepare("SELECT id FROM radcheck WHERE phone = ? LIMIT 1");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        return [
            'status' => false,
            'message' => 'Phone number already in use. Please use a different phone number or login to your existing account.'
        ];
    }
    

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    

//    $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, status, created_at) VALUES (?, ?, ?, ?, 'active', NOW())");
//    $result = $stmt->execute([$name, $email, $phone, $hashedPassword]);
    $stmt = $conn->prepare("INSERT INTO radcheck (name, email, phone, attribute, op, value) VALUES (?, ?, ?, 'Hash-Password', ':=', ?)");
    $result = $stmt->execute([$name, $email, $phone, $hashedPassword]);
    
    if (!$result) {
        return [
            'status' => false,
            'message' => 'Registration failed. Please try again later.'
        ];
    }
    
    $userId = $conn->lastInsertId();
    
    return [
        'status' => true,
        'message' => 'Registration successful! You can now login with your email and password.',
        'user_id' => $userId
    ];
}

function recoverPassword($email) {
    global $conn;
    
    // Sanitize input
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    
    // Check if email exists
    $stmt = $conn->prepare("SELECT id, name FROM radcheck WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return [
            'status' => false,
            'message' => 'If your email is registered, you will receive a password reset link shortly.'
        ];
    }
    
    // Generate token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour
    
    // Save token to database
    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'], $token, $expires]);
    

    $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/reset-password.php?token=" . $token;
    $subject = "Password Reset - Taransvar WiFi";
    $message = "Hello " . $user['name'] . ",\n\n";
    $message .= "You have requested to reset your password. Please click the link below to reset your password:\n\n";
    $message .= $resetLink . "\n\n";
    $message .= "This link will expire in 1 hour.\n\n";
    $message .= "If you did not request this, please ignore this email.\n\n";
    $message .= "Regards,\nTaransvar WiFi Team";
    

    error_log("Password reset email sent to: " . $email . " with token: " . $token);
    
    return [
        'status' => true,
        'message' => 'If your email is registered, you will receive a password reset link shortly.'
    ];
}


function generateSessionId() {
    return bin2hex(random_bytes(16));
}


function calculateExpiryTime($planType) {
    switch ($planType) {
        case 'hourly':
            return date('Y-m-d H:i:s', time() + 3600); // 1 hour
        case 'daily':
            return date('Y-m-d H:i:s', time() + 86400); // 24 hours
        case 'monthly':
            return date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 days
        default:
            return date('Y-m-d H:i:s', time() + 3600); // Default to 1 hour
    }
}
?>
