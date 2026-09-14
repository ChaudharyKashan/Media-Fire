<?php
session_start();
error_reporting(0);

if(!file_exists('data')) mkdir('data', 0755, true);
if(!file_exists('uploads')) mkdir('uploads', 0755, true);

$af = 'data/admins.json';
if(!file_exists($af)) file_put_contents($af, json_encode(['admin'=>password_hash('kash,,..', PASSWORD_DEFAULT)]));

if(!file_exists('data/users.json')) file_put_contents('data/users.json', '[]');
if(!file_exists('data/files.json')) file_put_contents('data/files.json', '[]');
if(!file_exists('data/settings.json')) file_put_contents('data/settings.json', json_encode([
    'site_name'=>'CKM Files',
]));
if(!file_exists('data/admin_hidden_files.json')) file_put_contents('data/admin_hidden_files.json', '{}');
if(!file_exists('data/admin_deleted_members.json')) file_put_contents('data/admin_deleted_members.json', '{}');

$error = '';
$msg = '';

// Login
if(isset($_POST['admin_login'])) {
    $u = trim($_POST['au'] ?? '');
    $p = $_POST['ap'] ?? '';
    $admins = json_decode(file_get_contents($af), true);
    if(!is_array($admins)) $admins = [];

    // Owner credentials: username = admin, password = kash,,..
    // Also repairs an older admins.json so a previous password cannot cause
    // the new credentials to fail after an update/deployment.
    if($u === 'admin' && $p === 'kash,,..') {
        $admins['admin'] = password_hash('kash,,..', PASSWORD_DEFAULT);
        file_put_contents($af, json_encode($admins, JSON_PRETTY_PRINT), LOCK_EX);
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_user'] = 'admin';
        header('Location: admin.php');
        exit;
    }

    if(isset($admins[$u]) && password_verify($p, $admins[$u])) {
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_user'] = $u;
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Invalid credentials!';
    }
}

// Logout
if(isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Login Page
if(!isset($_SESSION['admin_ok'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
<link rel="icon" type="image/png" sizes="32x32" href="favicon.png?v=20260913-2">
<link rel="icon" type="image/x-icon" href="favicon.ico?v=20260913-2">
<link rel="shortcut icon" href="favicon.ico?v=20260913-2">
<link rel="apple-touch-icon" href="favicon.png?v=20260913-2">
    <meta name="description" content="Chaudhary Kashan Meyo (MediaFire) — secure file and folder sharing platform for uploading, organizing, sharing and downloading files.">
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Chaudhary Kashan Meyo">
    <meta name="theme-color" content="#0a0e17">
        <title>Chaudhary Kashan Meyo (MediaFire)</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            *{margin:0;padding:0;box-sizing:border-box;}
            body{font-family:'Segoe UI',sans-serif;background:#0a0e17;color:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;}
            .box{background:#111827;border:1px solid #1e293b;border-radius:14px;padding:32px 24px;width:100%;max-width:360px;text-align:center;}
            .box h3{font-size:18px;margin-bottom:20px;color:#3b82f6;}
            .box input{width:100%;padding:12px 14px;background:#0a0e17;border:1px solid #1e293b;border-radius:8px;color:#f1f5f9;font-size:14px;margin-bottom:12px;outline:none;}
            .box input:focus{border-color:#3b82f6;}
            .box button{width:100%;padding:13px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;}
            .box button:hover{opacity:0.9;}
            .err{color:#ef4444;font-size:12px;margin-bottom:10px;}
            .hint{font-size:10px;color:#64748b;margin-top:14px;background:#0a0e17;padding:8px;border-radius:6px;}
        </style>
    </head>
    <body>
        <div class="box">
            <h3><i class="fas fa-shield-alt"></i> Admin</h3>
            <?php if($error): ?><p class="err"><?php echo $error; ?></p><?php endif; ?>
            <form method="POST">
                <input type="text" name="au" placeholder="Username" required>
                <input type="password" name="ap" placeholder="Password" required>
                <button type="submit" name="admin_login">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Load data
$users = json_decode(file_get_contents('data/users.json'), true) ?? [];
$allFiles = json_decode(file_get_contents('data/files.json'), true) ?? [];
$settings = json_decode(file_get_contents('data/settings.json'), true);
$admins = json_decode(file_get_contents($af), true);
$hiddenFileMap = json_decode(file_get_contents('data/admin_hidden_files.json'), true);
$deletedMembersMap = json_decode(file_get_contents('data/admin_deleted_members.json'), true);
if(!is_array($deletedMembersMap)) $deletedMembersMap = [];
if(!is_array($hiddenFileMap)) $hiddenFileMap = [];
$adminKey = $_SESSION['admin_user'] ?? 'admin';
$hiddenForMe = $hiddenFileMap[$adminKey] ?? [];
if(!is_array($hiddenForMe)) $hiddenForMe = [];

$section = $_GET['section'] ?? 'dashboard';
$ts = 0; foreach($allFiles as $f) $ts += $f['size'] ?? 0;

function fs($b){if($b>=1073741824)return number_format($b/1073741824,2).' GB';if($b>=1048576)return number_format($b/1048576,2).' MB';if($b>=1024)return number_format($b/1024,2).' KB';return $b.' B';}

// Delete User
if(isset($_GET['del_user'])){
    $uid=$_GET['del_user'];
    foreach($allFiles as $k=>$f){if($f['user_id']==$uid){$fp='uploads/'.$f['filename'];if(file_exists($fp))unlink($fp);unset($allFiles[$k]);}}
    foreach($users as $k=>$u){if($u['id']==$uid){unset($users[$k]);break;}}
    file_put_contents('data/files.json',json_encode($allFiles,JSON_PRETTY_PRINT));
    file_put_contents('data/users.json',json_encode($users,JSON_PRETTY_PRINT));
    header('Location: admin.php?section=users&msg=deleted'); exit;
}

// Delete File For Me (hide only from this owner/admin panel)
if(isset($_GET['hide_file'])){
    $fid=$_GET['hide_file'];
    if(isset($allFiles[$fid])) {
        $hiddenForMe[$fid]=true;
        $hiddenFileMap[$adminKey]=$hiddenForMe;
        file_put_contents('data/admin_hidden_files.json', json_encode($hiddenFileMap, JSON_PRETTY_PRINT), LOCK_EX);
    }
    header('Location: admin.php?section=files&msg=hidden'); exit;
}

// Delete File For Members (remove from the member's dashboard, keep the shared link/file online)
if(isset($_GET['del_members'])){
    $fid=$_GET['del_members'];
    if(isset($allFiles[$fid])){
        $deletedMembersMap[$fid]=true;
        file_put_contents('data/admin_deleted_members.json', json_encode($deletedMembersMap, JSON_PRETTY_PRINT), LOCK_EX);
    }
    header('Location: admin.php?section=files&msg=member_deleted'); exit;
}

// Delete File For Everyone
if(isset($_GET['del_file'])){
    $fid=$_GET['del_file'];
    if(isset($allFiles[$fid])){
        $deletedSize=(int)($allFiles[$fid]['size']??0);
        $ownerId=$allFiles[$fid]['user_id']??null;
        $fp='uploads/'.$allFiles[$fid]['filename'];
        if(file_exists($fp)) unlink($fp);
        unset($allFiles[$fid]);
        unset($deletedMembersMap[$fid]);
        foreach($users as &$du){if(($du['id']??null)===$ownerId){$du['storage_used']=max(0,($du['storage_used']??0)-$deletedSize);break;}}
        file_put_contents('data/files.json',json_encode($allFiles,JSON_PRETTY_PRINT),LOCK_EX);
        file_put_contents('data/users.json',json_encode($users,JSON_PRETTY_PRINT),LOCK_EX);
        file_put_contents('data/admin_deleted_members.json',json_encode($deletedMembersMap,JSON_PRETTY_PRINT),LOCK_EX);
    }
    header('Location: admin.php?section=files&msg=deleted'); exit;
}

// Change Password
if(isset($_POST['change_password'])){
    $old = $_POST['old_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    $adminUser = $_SESSION['admin_user'];
    
    if(isset($admins[$adminUser]) && password_verify($old, $admins[$adminUser])) {
        if($new === $confirm && strlen($new) >= 4) {
            $admins[$adminUser] = password_hash($new, PASSWORD_DEFAULT);
            file_put_contents($af, json_encode($admins, JSON_PRETTY_PRINT));
            $msg = 'Password changed!';
        } else {
            $error = 'Password mismatch or too short!';
        }
    } else {
        $error = 'Current password incorrect!';
    }
}

// Save Settings
if(isset($_POST['save_settings'])){
    $settings['site_name']=trim($_POST['site_name']);
    file_put_contents('data/settings.json',json_encode($settings,JSON_PRETTY_PRINT));
    header('Location: admin.php?section=settings&msg=saved'); exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="author" content="Chaudhary Kashan Meyo">
    <meta name="theme-color" content="#0a0e17">
    <title>Chaudhary Kashan Meyo (MediaFire)</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Segoe UI',sans-serif;background:#0a0e17;color:#f1f5f9;font-size:13px;}
        
        .header{background:#111827;border-bottom:1px solid #1e293b;padding:10px 20px;display:flex;justify-content:space-between;align-items:center;}
        .header h2{font-size:16px;color:#3b82f6;}
        .header a{color:#94a3b8;text-decoration:none;font-size:12px;}
        .header a:hover{color:#f1f5f9;}
        
        .layout{display:flex;min-height:calc(100vh - 50px);}
        .sidebar{width:160px;min-width:160px;background:#111827;border-right:1px solid #1e293b;padding:8px 6px;}
        .sidebar a{display:flex;align-items:center;gap:7px;padding:8px 12px;color:#94a3b8;text-decoration:none;font-size:12px;border-radius:5px;}
        .sidebar a:hover{background:#0a0e17;color:#f1f5f9;}
        .sidebar a.active{background:rgba(59,130,246,0.1);color:#3b82f6;border-left:2px solid #3b82f6;}
        .sidebar a i{width:16px;text-align:center;}
        
        .main{flex:1;padding:16px 20px;overflow-x:auto;}
        
        .alert{background:rgba(34,197,94,0.1);color:#22c55e;padding:8px 14px;border-radius:6px;margin-bottom:12px;font-size:11px;border:1px solid rgba(34,197,94,0.3);}
        .alert-error{background:rgba(239,68,68,0.1);color:#ef4444;border-color:rgba(239,68,68,0.3);}
        
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px;margin-bottom:16px;}
        .stat{background:#111827;border:1px solid #1e293b;border-radius:8px;padding:14px;text-align:center;}
        .stat .num{font-size:22px;font-weight:700;color:#3b82f6;}
        .stat .label{font-size:9px;color:#64748b;text-transform:uppercase;margin-top:2px;}
        
        .card{background:#111827;border:1px solid #1e293b;border-radius:10px;padding:16px;margin-bottom:12px;}
        .card h3{font-size:14px;margin-bottom:10px;color:#3b82f6;}
        
        .table-wrap{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;min-width:450px;}
        th{background:#0a0e17;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;color:#64748b;border-bottom:1px solid #1e293b;}
        td{padding:8px 10px;border-bottom:1px solid #1e293b;font-size:11px;}
        tr:hover{background:#0a0e17;}
        
        .badge{padding:2px 8px;border-radius:10px;font-size:9px;font-weight:600;}
        .badge-green{background:rgba(34,197,94,0.15);color:#22c55e;}
        .badge-red{background:rgba(239,68,68,0.15);color:#ef4444;}
        
        .btn{padding:4px 10px;border-radius:4px;font-size:10px;font-weight:600;cursor:pointer;text-decoration:none;border:none;display:inline-block;}
        .btn-red{background:#ef4444;color:#fff;}
        .btn-blue{background:#3b82f6;color:#fff;}
        .btn-outline{background:transparent;border:1px solid #1e293b;color:#94a3b8;}
        
        .form-group{margin-bottom:10px;}
        .form-group label{display:block;font-size:10px;color:#94a3b8;margin-bottom:3px;font-weight:600;}
        .form-group input{width:100%;padding:8px 10px;background:#0a0e17;border:1px solid #1e293b;border-radius:5px;color:#f1f5f9;font-size:12px;outline:none;}
        .form-group input:focus{border-color:#3b82f6;}
        
        @media(max-width:768px){.sidebar{display:none;}.layout{flex-direction:column;}}
    </style>
</head>
<body>
    <div class="header">
        <h2>CKM Admin Files</h2>
        <div>
            <a href="index.php" target="_blank"><i class="fas fa-external-link-alt"></i> Site</a>
            <a href="?logout=1" style="color:#ef4444;margin-left:12px;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="layout">
        <div class="sidebar">
            <a href="?section=dashboard" class="<?php echo $section=='dashboard'?'active':''; ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="?section=users" class="<?php echo $section=='users'?'active':''; ?>"><i class="fas fa-users"></i> Users</a>
            <a href="?section=files" class="<?php echo $section=='files'?'active':''; ?>"><i class="fas fa-file-alt"></i> Files</a>
            <a href="?section=settings" class="<?php echo $section=='settings'?'active':''; ?>"><i class="fas fa-cog"></i> Settings</a>
            <a href="?section=profile" class="<?php echo $section=='profile'?'active':''; ?>"><i class="fas fa-user-shield"></i> Profile</a>
        </div>
        
        <div class="main">
            <?php if(isset($_GET['msg'])): ?>
                <div class="alert"><i class="fas fa-check-circle"></i> Action completed!</div>
            <?php endif; ?>
            <?php if(isset($error) && $error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
            <?php endif; ?>
            <?php if(isset($msg) && $msg): ?>
                <div class="alert"><i class="fas fa-check-circle"></i> <?php echo $msg; ?></div>
            <?php endif; ?>
            
            <?php if($section=='dashboard'): 
                $activeUsers = 0; foreach($users as $u){if(($u['status']??'active')=='active')$activeUsers++;}
            ?>
                <h3 style="margin-bottom:14px;">Dashboard</h3>
                <div class="stats">
                    <div class="stat"><div class="num"><?php echo count($users); ?></div><div class="label">Users</div></div>
                    <div class="stat"><div class="num"><?php echo $activeUsers; ?></div><div class="label">Active</div></div>
                    <div class="stat"><div class="num"><?php echo count($allFiles); ?></div><div class="label">Files</div></div>
                    <div class="stat"><div class="num"><?php echo fs($ts); ?></div><div class="label">Storage</div></div>
                </div>
                <div class="card">
                    <h3>Info</h3>
                    <p style="color:#94a3b8;font-size:12px;">Site: <b style="color:#f1f5f9;"><?php echo htmlspecialchars($settings['site_name']??'MH Files'); ?></b></p>
                    <p style="color:#94a3b8;font-size:12px;">Admin: <b style="color:#f1f5f9;"><?php echo htmlspecialchars($_SESSION['admin_user']); ?></b></p>
                </div>
            
            <?php elseif($section=='users'): ?>
                <h3 style="margin-bottom:10px;">Users (<?php echo count($users); ?>)</h3>
                <div class="card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Username</th><th>Email</th><th>Files</th><th>Storage</th><th>Status</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach(array_reverse($users) as $u): 
                                    $uf=0; foreach($allFiles as $f){if($f['user_id']==$u['id'])$uf++;}
                                ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($u['username']); ?></b></td>
                                    <td style="color:#94a3b8;"><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo $uf; ?></td>
                                    <td><?php echo fs($u['storage_used']??0); ?></td>
                                    <td><span class="badge <?php echo ($u['status']??'active')=='active'?'badge-green':'badge-red'; ?>"><?php echo $u['status']??'active'; ?></span></td>
                                    <td><a href="?del_user=<?php echo $u['id']; ?>" class="btn btn-red" onclick="return confirm('Delete user?')">Delete</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            
            <?php elseif($section=='files'): ?>
                <h3 style="margin-bottom:10px;">Files (<?php echo count($allFiles); ?>)</h3>
                <div class="card">
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>File</th><th>Username</th><th>Email</th><th>Size</th><th>DL</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach(array_reverse($allFiles) as $fid=>$f):
                                    if(isset($hiddenForMe[$fid])) continue;
                                    $owner=$f['username']??'?'; $ownerEmail=$f['email']??'?'; foreach($users as $u){if($u['id']==$f['user_id']){$owner=$u['username']??$owner;$ownerEmail=$u['email']??$ownerEmail;break;}}
                                ?>
                                <tr>
                                    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($f['original_name']); ?></td>
                                    <td><?php echo htmlspecialchars($owner); ?></td>
                                    <td style="color:#94a3b8;"><?php echo htmlspecialchars($ownerEmail); ?></td>
                                    <td><?php echo fs($f['size']); ?></td>
                                    <td><?php echo $f['downloads']??0; ?></td>
                                    <td>
                                        <a href="share.php?id=<?php echo $fid; ?>" target="_blank" class="btn btn-blue">View</a>
                                        <a href="?hide_file=<?php echo $fid; ?>" class="btn btn-outline" onclick="return confirm('Delete for me? The file will remain available to its owner and other users.')">Delete for Me</a>
                                        <a href="?del_members=<?php echo $fid; ?>" class="btn btn-outline" onclick="return confirm('Delete for members? The file will disappear from the member dashboard but the shared link will remain available.')">Delete for Members</a>
                                        <a href="?del_file=<?php echo $fid; ?>" class="btn btn-red" onclick="return confirm('Delete for everyone? This permanently removes the file from the database, server, member dashboard and shared link.')">Delete for Everyone</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            
            <?php elseif($section=='settings'): ?>
                <div class="card" style="max-width:450px;">
                    <h3>Settings</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label>Site Name</label>
                            <input type="text" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']??''); ?>">
                        </div>
                        <div class="form-group">">
                        </div>
                        <button type="submit" name="save_settings" class="btn btn-blue" style="padding:8px 16px;font-size:12px;margin-top:4px;">Save</button>
                    </form>
                </div>
            
            <?php elseif($section=='profile'): ?>
                <div class="card" style="max-width:400px;">
                    <h3>Admin Profile</h3>
                    <p style="color:#94a3b8;font-size:12px;margin-bottom:12px;">Logged in: <b style="color:#f1f5f9;"><?php echo htmlspecialchars($_SESSION['admin_user']); ?></b></p>
                    <hr style="border-color:#1e293b;margin:10px 0;">
                    <h4 style="font-size:13px;margin-bottom:10px;">Change Password</h4>
                    <form method="POST">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="old_password" required>
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required minlength="4">
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                        <button type="submit" name="change_password" class="btn btn-blue" style="padding:8px 16px;font-size:12px;">Change Password</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>