<?php
if(!isset($_GET['id'])) { header('Location: index.php'); exit; }
$fid = $_GET['id'];
if(!file_exists('data/files.json')) die('File not found');
$allFiles = json_decode(file_get_contents('data/files.json'), true) ?? [];
if(!isset($allFiles[$fid])) die('File not found');
$file = $allFiles[$fid];

function fs($b){
    if($b>=1073741824) return number_format($b/1073741824,2).' GB';
    if($b>=1048576) return number_format($b/1048576,2).' MB';
    if($b>=1024) return number_format($b/1024,2).' KB';
    return $b.' B';
}

$ext = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
$fi = ['fa-file','#94a3b8'];
if(in_array($ext,['jpg','jpeg','png','gif','webp'])) $fi=['fa-file-image','#60a5fa'];
elseif(in_array($ext,['mp4','avi','mkv','webm'])) $fi=['fa-file-video','#f87171'];
elseif(in_array($ext,['mp3','wav','flac'])) $fi=['fa-file-audio','#fbbf24'];
elseif($ext=='pdf') $fi=['fa-file-pdf','#ef4444'];
elseif(in_array($ext,['zip','rar','7z'])) $fi=['fa-file-zipper','#a78bfa'];

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
        body{font-family:'Segoe UI',sans-serif;background:#0a0e17;color:#f1f5f9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
        .container{width:100%;max-width:400px;}
        .top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;}
        .top .brand{font-size:16px;font-weight:700;color:#3b82f6;}
        .top .brand span{color:#f1f5f9;}
        
        .card{background:#111827;border:1px solid #1e293b;border-radius:14px;padding:24px 18px;text-align:center;}
        .card .icon{width:60px;height:60px;background:#0a0e17;border:1px solid #1e293b;border-radius:14px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;color:<?php echo $fi[1]; ?>;margin-bottom:12px;}
        .card h2{font-size:15px;word-break:break-all;margin-bottom:8px;}
        .card .tags{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-bottom:14px;}
        .card .tags .tag{font-size:10px;padding:4px 10px;border-radius:12px;background:rgba(59,130,246,0.1);color:#60a5fa;}
        .card .grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
        .card .grid .box{background:#0a0e17;border:1px solid #1e293b;border-radius:8px;padding:10px;}
        .card .grid .box .lbl{font-size:9px;color:#64748b;text-transform:uppercase;}
        .card .grid .box .val{font-size:14px;font-weight:600;}
        .btn{display:block;width:100%;padding:12px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;text-align:center;border:none;cursor:pointer;font-family:inherit;}
        .btn-primary{background:#3b82f6;color:#fff;}
        .btn-primary:hover{opacity:0.9;}
        .btn-outline{background:#0a0e17;border:1px solid #1e293b;color:#f1f5f9;margin-top:8px;}
    </style>
</head>
<body>
    <div class="container">
        <div class="top">
            <div class="brand">CKM<span>Files</span></div>
        </div>
        
        <div class="card">
            <div class="icon"><i class="far <?php echo $fi[0]; ?>"></i></div>
            <h2><?php echo htmlspecialchars($file['original_name']); ?></h2>
            <div class="tags">
                <span class="tag"><i class="fas fa-hdd"></i> <?php echo fs($file['size']); ?></span>
                <span class="tag"><i class="fas fa-download"></i> <?php echo $file['downloads']??0; ?></span>
            </div>
            <div class="grid">
                <div class="box"><div class="lbl">Size</div><div class="val"><?php echo fs($file['size']); ?></div></div>
                <div class="box"><div class="lbl">Downloads</div><div class="val"><?php echo $file['downloads']??0; ?></div></div>
                <div class="box"><div class="lbl">Type</div><div class="val"><?php echo strtoupper($ext); ?></div></div>
                <div class="box"><div class="lbl">Uploaded</div><div class="val"><?php echo date('d M',strtotime($file['date'])); ?></div></div>
            </div>
            <a href="download.php?id=<?php echo $fid; ?>" class="btn btn-primary"><i class="fas fa-download"></i> Download</a>
            <button class="btn btn-outline" onclick="copyLink()"><i class="fas fa-link"></i> Copy Link</button>
        </div>
    </div>
    
    <script>
        function copyLink(){
            navigator.clipboard.writeText(window.location.href);
            alert('Link copied!');
        }
    </script>
</body>
</html>