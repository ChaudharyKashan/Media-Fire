<?php
session_start();
if(!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

$users = json_decode(file_get_contents('data/users.json'), true) ?? [];
$current_user = null;
foreach($users as &$u) { 
    if($u['id']==$_SESSION['user_id']) { 
        $current_user=&$u; 
        break; 
    } 
}

$msg = ''; $error = '';

// Update Username
if(isset($_POST['update_profile'])) {
    $newUsername = trim($_POST['username']);
    if(!empty($newUsername)) {
        $current_user['username'] = $newUsername;
        foreach($users as &$u) { 
            if($u['id']==$current_user['id']) { 
                $u=$current_user; 
                break; 
            } 
        }
        file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT));
        $_SESSION['username'] = $newUsername;
        $msg = 'Username updated!';
    }
}

// Change Password
if(isset($_POST['change_password'])) {
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    
    if(!password_verify($old, $current_user['password'])) {
        $error = 'Current password incorrect!';
    } elseif($new !== $confirm) {
        $error = 'Passwords do not match!';
    } elseif(strlen($new) < 4) {
        $error = 'Password too short!';
    } else {
        $current_user['password'] = password_hash($new, PASSWORD_DEFAULT);
        foreach($users as &$u) { 
            if($u['id']==$current_user['id']) { 
                $u=$current_user; 
                break; 
            } 
        }
        file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT));
        $msg = 'Password changed!';
    }
}

function fs($b){
    if($b>=1073741824) return number_format($b/1073741824,2).' GB';
    if($b>=1048576) return number_format($b/1048576,2).' MB';
    if($b>=1024) return number_format($b/1024,2).' KB';
    return $b.' B';
}

$settings = json_decode(file_get_contents('data/settings.json'), true);
$siteName = $settings['site_name'] ?? 'CKM Files';
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="description" content="Chaudhary Kashan Meyo (MediaFire) — secure file and folder sharing platform for uploading, organizing, sharing and downloading files.">
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
        body{font-family:'Segoe UI',sans-serif;background:#0a0e17;color:#f1f5f9;font-size:13px;min-height:100vh;}
        .topbar{background:#111827;border-bottom:1px solid #1e293b;padding:0 12px;height:44px;display:flex;align-items:center;gap:10px;}
        .topbar .back{color:#94a3b8;text-decoration:none;font-size:14px;}
        .topbar h3{font-size:14px;}
        .container{max-width:400px;margin:0 auto;padding:16px;}
        .card{background:#111827;border:1px solid #1e293b;border-radius:12px;padding:16px;margin-bottom:12px;}
        .card h4{font-size:13px;color:#3b82f6;margin-bottom:10px;}
        .avatar{text-align:center;padding:10px 0;}
        .avatar .circle{width:50px;height:50px;background:#3b82f6;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;color:#fff;}
        .avatar h3{font-size:15px;margin-top:6px;}
        .avatar .email{font-size:11px;color:#64748b;}
        .row{display:flex;justify-content:space-between;padding:4px 0;font-size:11px;border-bottom:1px solid #1a2332;}
        .row .lbl{color:#94a3b8;}
        .field{margin-bottom:10px;}
        .field label{display:block;font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:2px;}
        .field input{width:100%;padding:8px 10px;background:#0a0e17;border:1px solid #1e293b;border-radius:6px;color:#f1f5f9;font-size:13px;outline:none;}
        .field input:focus{border-color:#3b82f6;}
        .field input:disabled{opacity:0.5;}
        .btn{padding:10px 16px;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;background:#3b82f6;color:#fff;}
        .btn:hover{opacity:0.9;}
        .alert{padding:8px 12px;border-radius:6px;font-size:11px;margin-bottom:10px;}
        .alert-success{background:rgba(34,197,94,0.1);color:#22c55e;border:1px solid rgba(34,197,94,0.2);}
        .alert-error{background:rgba(239,68,68,0.1);color:#ef4444;border:1px solid rgba(239,68,68,0.2);}
    </style>
</head>
<body>
    <div class="topbar">
        <a href="dashboard.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h3>Profile</h3>
    </div>
    
    <div class="container">
        <?php if($msg): ?><div class="alert alert-success"><?php echo $msg; ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-error"><?php echo $error; ?></div><?php endif; ?>
        
        <div class="card">
            <div class="avatar">
                <div class="circle"><?php echo strtoupper(substr($_SESSION['username'],0,1)); ?></div>
                <h3><?php echo htmlspecialchars($_SESSION['username']); ?></h3>
                <div class="email"><?php echo htmlspecialchars($current_user['email']); ?></div>
            </div>
            <div class="row"><span class="lbl">Storage Used</span><span><?php echo fs($current_user['storage_used']??0); ?></span></div>
            <div class="row"><span class="lbl">Member Since</span><span><?php echo date('d M Y',strtotime($current_user['created_at'])); ?></span></div>
        </div>
        
        <div class="card">
            <h4>Edit Profile</h4>
            <form method="POST">
                <div class="field">
                    <label>Username</label>
                    <input type="text" name="username" value="<?php echo htmlspecialchars($current_user['username']); ?>" required>
                </div>
                <div class="field">
                    <label>Email (Cannot be changed)</label>
                    <input type="email" value="<?php echo htmlspecialchars($current_user['email']); ?>" disabled>
                </div>
                <button type="submit" name="update_profile" class="btn">Update Profile</button>
            </form>
        </div>
        
        <div class="card">
            <h4>Change Password</h4>
            <form method="POST">
                <div class="field"><label>Current Password</label><input type="password" name="old_password" required></div>
                <div class="field"><label>New Password</label><input type="password" name="new_password" required></div>
                <div class="field"><label>Confirm Password</label><input type="password" name="confirm_password" required></div>
                <button type="submit" name="change_password" class="btn">Change Password</button>
            </form>
        </div>
    </div>
</body>
</html>