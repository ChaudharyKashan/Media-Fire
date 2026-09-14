<?php
session_start();
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (!is_dir('data')) { mkdir('data', 0755, true); }
if (!is_dir('uploads')) { mkdir('uploads', 0755, true); }
if (!file_exists('data/users.json')) { file_put_contents('data/users.json', '[]'); }
if (!file_exists('data/files.json')) { file_put_contents('data/files.json', '[]'); }
if (!file_exists('data/folders.json')) { file_put_contents('data/folders.json', '[]'); }
if (!file_exists('data/settings.json')) { 
    file_put_contents('data/settings.json', json_encode([
        'site_name'=>'CKM Files',
    ])); 
}

$error = '';
$success = '';

if (isset($_POST['register'])) {
    $username = trim($_POST['username'] ?? '');
    $usernameKey = strtolower($username);
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if ($username === '' || $email === '' || $password === '') {
        $error = 'All fields are required!';
    } elseif (strlen($password) < 4) {
        $error = 'Password too short!';
    } else {
        $users = json_decode(file_get_contents('data/users.json'), true);
        if (!is_array($users)) $users = [];
        $exists = false;
        foreach ($users as $u) { 
            if (strtolower(trim($u['email']??''))===strtolower($email) || strtolower(trim($u['username']??''))===$usernameKey) { 
                $exists=true; 
                break; 
            } 
        }
        if ($exists) { 
            $error = 'Username already exists!'; 
        } else {
            $users[] = [
                'id'=>'u'.time().rand(100,999),
                'username'=>$username,
                'email'=>$email,
                'password'=>password_hash($password, PASSWORD_DEFAULT),
                'storage_used'=>0,
                'created_at'=>date('Y-m-d H:i:s'),
                'status'=>'active'
            ];
            file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT));
            $success = 'Account created! Please login.';
        }
    }
}

if (isset($_POST['login'])) {
    $loginInput = trim($_POST['login_input'] ?? '');
    $password = trim($_POST['login_pass'] ?? '');
    
    if ($loginInput === '' || $password === '') {
        $error = 'Please enter email/username and password!';
    } else {
        $users = json_decode(file_get_contents('data/users.json'), true);
        if (!is_array($users)) $users = [];
        $found = null;
        foreach ($users as $u) {
            if ((strtolower($u['email']??'')===strtolower($loginInput) || 
                 strtolower($u['username']??'')===strtolower($loginInput)) && 
                 isset($u['password'])) {
                if (password_verify($password, $u['password'])) { 
                    $found = $u; 
                    break; 
                }
            }
        }
        if ($found) {
            if (($found['status']??'active')==='blocked') { 
                $error = 'Account blocked!'; 
            } else {
                $_SESSION['user_id']=$found['id']; 
                $_SESSION['username']=$found['username']; 
                $_SESSION['user_email']=$found['email'];
                header('Location: dashboard.php'); 
                exit;
            }
        } else { 
            $error = 'Invalid credentials!'; 
        }
    }
}

$settings = json_decode(file_get_contents('data/settings.json'), true);
$siteName = $settings['site_name'] ?? 'CKM Files';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="description" content="Chaudhary Kashan Meyo (MediaFire) — a fast and secure file and folder sharing platform for uploading, organizing, sharing and downloading files.">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Chaudhary Kashan Meyo">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png?v=20260913-2">
<link rel="icon" type="image/x-icon" href="favicon.ico?v=20260913-2">
<link rel="shortcut icon" href="favicon.ico?v=20260913-2">
<link rel="apple-touch-icon" href="favicon.png?v=20260913-2">
    <meta name="theme-color" content="#0a0e17">
    <title>Chaudhary Kashan Meyo (MediaFire)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Segoe UI',system-ui,sans-serif;background:#0a0e17;color:#f1f5f9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .container{width:100%;max-width:400px;}
        .brand{text-align:center;margin-bottom:24px;}
        .brand .logo{font-size:32px;font-weight:800;color:#3b82f6;}
        .brand .sub{font-size:13px;color:#64748b;margin-top:4px;}
        .card{background:#111827;border:1px solid #1e293b;border-radius:14px;padding:28px 22px;}
        .tabs{display:flex;gap:6px;margin-bottom:20px;background:#0a0e17;border-radius:8px;padding:4px;}
        .tab{flex:1;padding:10px;text-align:center;color:#94a3b8;font-size:13px;font-weight:600;cursor:pointer;border-radius:6px;border:none;background:none;}
        .tab.active{background:#3b82f6;color:#fff;}
        .field{margin-bottom:14px;}
        .field label{display:block;font-size:11px;color:#94a3b8;margin-bottom:4px;font-weight:600;}
        .field input{width:100%;padding:10px 12px;background:#0a0e17;border:1px solid #1e293b;border-radius:8px;color:#f1f5f9;font-size:14px;outline:none;}
        .field input:focus{border-color:#3b82f6;}
        .btn{width:100%;padding:12px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;}
        .btn:hover{opacity:0.9;}
        .alert{padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#86efac;}
    </style>
</head>
<body>
    <div class="container">
        <div class="brand">
            <div class="logo">CKM Files</div>
            <div class="sub">Secure Cloud Storage</div>
        </div>
        <div class="card">
            <?php if($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
            <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('login')">Sign In</button>
                <button class="tab" onclick="switchTab('register')">Register</button>
            </div>
            
            <form method="POST" id="form-login">
                <div class="field">
                    <label>Email or Username</label>
                    <input type="text" name="login_input" placeholder="Enter email or username" required>
                </div>
                <div class="field">
                    <label>Password</label>
                    <input type="password" name="login_pass" placeholder="Enter password" required>
                </div>
                <button type="submit" name="login" class="btn">Sign In</button>
            </form>
            
            <form method="POST" id="form-register" style="display:none;">
                <div class="field">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Choose username" required>
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="Enter email" required>
                </div>
                <div class="field">
                    <label>Password (min 4 chars)</label>
                    <input type="password" name="password" placeholder="Create password" required minlength="4">
                </div>
                <button type="submit" name="register" class="btn">Create Account</button>
            </form>
        </div>
    </div>
    
    <script>
        function switchTab(t) {
            document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('form-login').style.display = t === 'login' ? 'block' : 'none';
            document.getElementById('form-register').style.display = t === 'register' ? 'block' : 'none';
        }
    </script>
</body>
</html>