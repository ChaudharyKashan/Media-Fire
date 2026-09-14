<?php
session_start();
if(!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

if(!file_exists('data')) mkdir('data', 0755, true);
if(!file_exists('uploads')) mkdir('uploads', 0755, true);
if(!file_exists('data/files.json')) file_put_contents('data/files.json', '[]');
if(!file_exists('data/folders.json')) file_put_contents('data/folders.json', '[]');

$users = json_decode(file_get_contents('data/users.json'), true) ?? [];
$allFiles = json_decode(file_get_contents('data/files.json'), true) ?? [];
$allFolders = json_decode(file_get_contents('data/folders.json'), true) ?? [];
$deletedMembersMap = file_exists('data/admin_deleted_members.json') ? (json_decode(file_get_contents('data/admin_deleted_members.json'), true) ?? []) : [];

$current_user = null;
foreach($users as &$u) { 
    if($u['id']==$_SESSION['user_id']) { 
        $current_user=&$u; 
        break; 
    } 
}
if(!$current_user || ($current_user['status']??'active')=='blocked') { 
    session_destroy(); 
    header('Location: index.php'); 
    exit; 
}

$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : '0';

// AJAX Create Folder (used from Move/Copy destination picker)
if(isset($_POST['ajax_create_folder'])) {
    header('Content-Type: application/json; charset=utf-8');
    $fn = trim((string)($_POST['folder_name'] ?? ''));
    $pid = (string)($_POST['parent_id'] ?? $currentFolder);
    $validParent = ($pid === '0') || (isset($allFolders[$pid]) && ($allFolders[$pid]['user_id'] ?? '') === $current_user['id']);
    if($fn === '' || !$validParent) { echo json_encode(['ok'=>false,'message'=>'Invalid folder name or destination.']); exit; }
    $nid = 'f_' . time() . '_' . bin2hex(random_bytes(4));
    $allFolders[$nid] = [
        'id' => $nid, 'name' => $fn, 'parent_id' => $pid,
        'user_id' => $current_user['id'], 'created_at' => date('Y-m-d H:i:s')
    ];
    file_put_contents('data/folders.json', json_encode($allFolders, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['ok'=>true,'id'=>$nid,'name'=>$fn,'parent_id'=>$pid]); exit;
}

// Create Folder
if(isset($_POST['create_folder'])) { 
    $fn = trim($_POST['folder_name']); 
    $pid = $_POST['parent_id'] ?? '0'; 
    if(!empty($fn)){
        $nid = 'f_' . time() . '_' . rand(1000, 9999);
        $allFolders[$nid] = [
            'id' => $nid,
            'name' => $fn,
            'parent_id' => $pid,
            'user_id' => $current_user['id'],
            'created_at' => date('Y-m-d H:i:s')
        ];
        file_put_contents('data/folders.json', json_encode($allFolders, JSON_PRETTY_PRINT));
        header('Location: dashboard.php?folder=' . $nid); 
        exit;
    } 
}

// Bulk actions for long-press/multi-select.
if(isset($_POST['bulk_action'])) {
    $action = (string)($_POST['bulk_action'] ?? '');
    $selected = json_decode($_POST['selected_items'] ?? '[]', true);
    if(!is_array($selected)) $selected = [];
    $items = [];
    foreach($selected as $it) {
        if(!is_array($it)) continue;
        $kind = ($it['kind'] ?? '') === 'folder' ? 'folder' : 'file';
        $id = (string)($it['id'] ?? '');
        if($id==='') continue;
        if($kind==='file' && isset($allFiles[$id]) && ($allFiles[$id]['user_id'] ?? '') === $current_user['id']) $items[]=['kind'=>'file','id'=>$id];
        if($kind==='folder' && isset($allFolders[$id]) && ($allFolders[$id]['user_id'] ?? '') === $current_user['id']) $items[]=['kind'=>'folder','id'=>$id];
    }

    // If a selected folder contains another selected folder, operate only on the top-level folder.
    $selectedFolderIds=[]; foreach($items as $it) if($it['kind']==='folder') $selectedFolderIds[$it['id']]=true;
    if($selectedFolderIds){
        $top=[];
        foreach($selectedFolderIds as $fid=>$_){
            $walk=$allFolders[$fid]['parent_id']??'0'; $inside=false; $guard=0;
            while($walk!=='0' && $guard++<200 && isset($allFolders[$walk])){
                if(isset($selectedFolderIds[$walk])){$inside=true;break;}
                $walk=$allFolders[$walk]['parent_id']??'0';
            }
            if(!$inside) $top[$fid]=true;
        }
        $items=array_values(array_filter($items,function($it) use ($top){ return $it['kind']!=='folder' || isset($top[$it['id']]); }));
    }

    if($action==='move' || $action==='copy') {
        $target=(string)($_POST['target_folder'] ?? $currentFolder);
        $validTarget=($target==='0') || (isset($allFolders[$target]) && ($allFolders[$target]['user_id'] ?? '')===$current_user['id']);
        if($validTarget) {
            // Move folders: reject any destination that is the selected folder itself or a descendant.
            $blocked=[];
            foreach($items as $it) if($it['kind']==='folder') $blocked[$it['id']]=true;
            $changed=true;
            while($changed){
                $changed=false;
                foreach($allFolders as $fk=>$ff){
                    $parent=(string)($ff['parent_id']??'0');
                    if(isset($blocked[$parent]) && !isset($blocked[$fk])){ $blocked[$fk]=true; $changed=true; }
                }
            }
            if(!isset($blocked[$target])) {
                if($action==='move') {
                    foreach($items as $it) {
                        if($it['kind']==='file') $allFiles[$it['id']]['folder_id']=$target;
                        else $allFolders[$it['id']]['parent_id']=$target;
                    }
                } else {
                    // Recursive copy of selected folders, including their files and subfolders.
                    $copyFolderTree=function($oldId,$newParent) use (&$copyFolderTree,&$allFolders,&$allFiles,&$current_user){
                        $old=$allFolders[$oldId];
                        $newId='f_'.time().'_'.bin2hex(random_bytes(4));
                        $allFolders[$newId]=$old;
                        $allFolders[$newId]['id']=$newId;
                        $allFolders[$newId]['name']=$old['name'].' (Copy)';
                        $allFolders[$newId]['parent_id']=$newParent;
                        $allFolders[$newId]['created_at']=date('Y-m-d H:i:s');
                        foreach($allFiles as $fk=>$file){
                            if(($file['folder_id']??'0')===$oldId && ($file['user_id']??'')===$current_user['id']){
                                $src='uploads/'.$file['filename'];
                                if(file_exists($src)){
                                    $ext=pathinfo($file['original_name'],PATHINFO_EXTENSION);
                                    $newStored=uniqid('',true).'_'.time().($ext!==''?'.'.$ext:'');
                                    if(copy($src,'uploads/'.$newStored)){
                                        $newFileId=substr(md5(uniqid('',true).random_bytes(8)),0,10);
                                        $newFile=$file; $newFile['filename']=$newStored;
                                        $newFile['original_name']=pathinfo($file['original_name'],PATHINFO_FILENAME).' (Copy)'.($ext!==''?'.'.$ext:'');
                                        $newFile['folder_id']=$newId; $newFile['date']=date('Y-m-d H:i:s'); $newFile['downloads']=0;
                                        $allFiles[$newFileId]=$newFile;
                                        $current_user['storage_used']=($current_user['storage_used']??0)+(int)$newFile['size'];
                                    }
                                }
                            }
                        }
                        $children=[];
                        foreach($allFolders as $cid=>$child) if($cid!==$oldId && ($child['parent_id']??'0')===$oldId && ($child['user_id']??'')===$current_user['id']) $children[]=$cid;
                        foreach($children as $cid) $copyFolderTree($cid,$newId);
                    };
                    foreach($items as $it){
                        if($it['kind']==='file'){
                            $file=$allFiles[$it['id']]; $src='uploads/'.$file['filename'];
                            if(file_exists($src)){
                                $ext=pathinfo($file['original_name'],PATHINFO_EXTENSION); $newStored=uniqid('',true).'_'.time().($ext!==''?'.'.$ext:'');
                                if(copy($src,'uploads/'.$newStored)){
                                    $newId=substr(md5(uniqid('',true).random_bytes(8)),0,10); $new=$file;
                                    $new['filename']=$newStored; $new['original_name']=pathinfo($file['original_name'],PATHINFO_FILENAME).' (Copy)'.($ext!==''?'.'.$ext:'');
                                    $new['folder_id']=$target; $new['date']=date('Y-m-d H:i:s'); $new['downloads']=0; $allFiles[$newId]=$new;
                                    $current_user['storage_used']=($current_user['storage_used']??0)+(int)$new['size'];
                                }
                            }
                        } else $copyFolderTree($it['id'],$target);
                    }
                }
            }
        }
    } elseif($action==='delete') {
        foreach($items as $it){
            if($it['kind']==='file'){
                $fp='uploads/'.$allFiles[$it['id']]['filename']; if(file_exists($fp)) unlink($fp);
                $current_user['storage_used']=max(0,($current_user['storage_used']??0)-(int)$allFiles[$it['id']]['size']); unset($allFiles[$it['id']]);
            } else {
                if(isset($allFolders[$it['id']])) { delFolder($it['id'],$allFolders,$allFiles,$current_user,$users); unset($allFolders[$it['id']]); }
            }
        }
    }
    foreach($users as &$x) if($x['id']===$current_user['id']) {$x=$current_user; break;}
    file_put_contents('data/files.json',json_encode($allFiles,JSON_PRETTY_PRINT),LOCK_EX);
    file_put_contents('data/folders.json',json_encode($allFolders,JSON_PRETTY_PRINT),LOCK_EX);
    file_put_contents('data/users.json',json_encode($users,JSON_PRETTY_PRINT),LOCK_EX);
    header('Location: dashboard.php?folder='.urlencode($currentFolder)); exit;
}

// Move File / Folder
if(isset($_POST['move_file']) || isset($_POST['move_folder'])) {
    $kind = isset($_POST['move_folder']) ? 'folder' : 'file';
    $id = (string)($_POST['file_id'] ?? $_POST['folder_id'] ?? '');
    $target = (string)($_POST['target_folder'] ?? '0');
    $validTarget = ($target === '0');
    if(!$validTarget && isset($allFolders[$target]) && ($allFolders[$target]['user_id'] ?? '') === $current_user['id']) $validTarget = true;

    // A folder cannot be moved into itself or any of its descendants.
    $blocked = false;
    if($kind === 'folder' && isset($allFolders[$id]) && $validTarget) {
        $walk = $target; $guard = 0;
        while($walk !== '0' && $guard++ < 100) {
            if($walk === $id) { $blocked = true; break; }
            $walk = $allFolders[$walk]['parent_id'] ?? '0';
        }
    }
    if($validTarget && !$blocked) {
        if($kind === 'file' && isset($allFiles[$id]) && ($allFiles[$id]['user_id'] ?? '') === $current_user['id']) {
            $allFiles[$id]['folder_id'] = $target;
            file_put_contents('data/files.json', json_encode($allFiles, JSON_PRETTY_PRINT), LOCK_EX);
        } elseif($kind === 'folder' && isset($allFolders[$id]) && ($allFolders[$id]['user_id'] ?? '') === $current_user['id']) {
            $allFolders[$id]['parent_id'] = $target;
            file_put_contents('data/folders.json', json_encode($allFolders, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
    header('Location: dashboard.php?folder=' . urlencode($currentFolder));
    exit;
}

// Copy File
if(isset($_POST['copy_file'])) {
    $fid = (string)($_POST['file_id'] ?? '');
    $copyTarget = (string)($_POST['target_folder'] ?? $currentFolder);
    $validCopyTarget = ($copyTarget === '0') || (isset($allFolders[$copyTarget]) && ($allFolders[$copyTarget]['user_id'] ?? '') === $current_user['id']);
    if($validCopyTarget && isset($allFiles[$fid]) && ($allFiles[$fid]['user_id'] ?? '') === $current_user['id']) {
        $src = 'uploads/' . $allFiles[$fid]['filename'];
        if(file_exists($src)) {
            $ext = pathinfo($allFiles[$fid]['original_name'], PATHINFO_EXTENSION);
            $newStored = uniqid('', true) . '_' . time() . ($ext !== '' ? '.' . $ext : '');
            if(copy($src, 'uploads/' . $newStored)) {
                $newId = substr(md5(uniqid('', true) . random_bytes(8)), 0, 10);
                $copy = $allFiles[$fid];
                $copy['filename'] = $newStored;
                $copy['original_name'] = pathinfo($copy['original_name'], PATHINFO_FILENAME) . ' (Copy)' . ($ext !== '' ? '.' . $ext : '');
                $copy['date'] = date('Y-m-d H:i:s');
                $copy['downloads'] = 0;
                $copy['folder_id'] = $copyTarget;
                $allFiles[$newId] = $copy;
                $current_user['storage_used'] = ($current_user['storage_used'] ?? 0) + (int)$copy['size'];
                foreach($users as &$x) if($x['id'] === $current_user['id']) { $x = $current_user; break; }
                file_put_contents('data/files.json', json_encode($allFiles, JSON_PRETTY_PRINT), LOCK_EX);
                file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }
    }
    header('Location: dashboard.php?folder=' . urlencode($currentFolder));
    exit;
}

// Copy Folder (recursive duplicate)
if(isset($_POST['copy_folder'])) {
    $sourceId = (string)($_POST['folder_id'] ?? '');
    $copyTarget = (string)($_POST['target_folder'] ?? $currentFolder);
    $validCopyTarget = ($copyTarget === '0') || (isset($allFolders[$copyTarget]) && ($allFolders[$copyTarget]['user_id'] ?? '') === $current_user['id']);
    $copyBlocked = false;
    if($validCopyTarget && isset($allFolders[$sourceId])) {
        $walk=$copyTarget; $guard=0;
        while($walk!=='0' && $guard++<200 && isset($allFolders[$walk])) {
            if($walk===$sourceId){$copyBlocked=true;break;}
            $walk=$allFolders[$walk]['parent_id']??'0';
        }
    }
    if($validCopyTarget && !$copyBlocked && isset($allFolders[$sourceId]) && ($allFolders[$sourceId]['user_id'] ?? '') === $current_user['id']) {
        $copyFolderTree = function($oldId, $newParent) use (&$copyFolderTree, &$allFolders, &$allFiles, &$current_user) {
            $old = $allFolders[$oldId];
            $newId = 'f_' . time() . '_' . bin2hex(random_bytes(4));
            $newName = $old['name'] . ' (Copy)';
            $allFolders[$newId] = $old;
            $allFolders[$newId]['id'] = $newId;
            $allFolders[$newId]['name'] = $newName;
            $allFolders[$newId]['parent_id'] = $newParent;
            $allFolders[$newId]['created_at'] = date('Y-m-d H:i:s');
            foreach($allFiles as $fk => $file) {
                if(($file['folder_id'] ?? '0') === $oldId && ($file['user_id'] ?? '') === $current_user['id']) {
                    $src = 'uploads/' . $file['filename'];
                    if(file_exists($src)) {
                        $ext = pathinfo($file['original_name'], PATHINFO_EXTENSION);
                        $newStored = uniqid('', true) . '_' . time() . ($ext !== '' ? '.' . $ext : '');
                        if(copy($src, 'uploads/' . $newStored)) {
                            $newFileId = substr(md5(uniqid('', true) . random_bytes(8)), 0, 10);
                            $newFile = $file;
                            $newFile['filename'] = $newStored;
                            $newFile['original_name'] = pathinfo($file['original_name'], PATHINFO_FILENAME) . ' (Copy)' . ($ext !== '' ? '.' . $ext : '');
                            $newFile['folder_id'] = $newId;
                            $newFile['date'] = date('Y-m-d H:i:s');
                            $newFile['downloads'] = 0;
                            $allFiles[$newFileId] = $newFile;
                            $current_user['storage_used'] = ($current_user['storage_used'] ?? 0) + (int)$newFile['size'];
                        }
                    }
                }
            }
            foreach($allFolders as $childId => $child) {
                if($childId !== $newId && $childId !== $oldId && ($child['parent_id'] ?? '0') === $oldId && ($child['user_id'] ?? '') === $current_user['id']) {
                    $copyFolderTree($childId, $newId);
                }
            }
            return $newId;
        };
        $copyFolderTree($sourceId, $copyTarget);
        foreach($users as &$x) if($x['id'] === $current_user['id']) { $x = $current_user; break; }
        file_put_contents('data/folders.json', json_encode($allFolders, JSON_PRETTY_PRINT), LOCK_EX);
        file_put_contents('data/files.json', json_encode($allFiles, JSON_PRETTY_PRINT), LOCK_EX);
        file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT), LOCK_EX);
    }
    header('Location: dashboard.php?folder=' . urlencode($currentFolder));
    exit;
}

// Delete File
if(isset($_GET['delete_file'])) { 
    $fid = $_GET['delete_file'];
    if(isset($allFiles[$fid]) && $allFiles[$fid]['user_id'] == $current_user['id']){
        $fp = 'uploads/' . $allFiles[$fid]['filename'];
        if(file_exists($fp)) unlink($fp);
        $current_user['storage_used'] -= $allFiles[$fid]['size'];
        unset($allFiles[$fid]);
        foreach($users as &$x){ if($x['id'] == $current_user['id']){ $x = $current_user; break; } }
        file_put_contents('data/files.json', json_encode($allFiles, JSON_PRETTY_PRINT));
        file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT));
        header('Location: dashboard.php?folder=' . $currentFolder); 
        exit;
    } 
}

// Delete Folder
if(isset($_GET['delete_folder'])) { 
    $fid = $_GET['delete_folder'];
    if(isset($allFolders[$fid]) && ($allFolders[$fid]['user_id'] ?? '') == $current_user['id']){
        delFolder($fid, $allFolders, $allFiles, $current_user, $users);
        unset($allFolders[$fid]);
        file_put_contents('data/folders.json', json_encode($allFolders, JSON_PRETTY_PRINT));
        file_put_contents('data/files.json', json_encode($allFiles, JSON_PRETTY_PRINT));
        file_put_contents('data/users.json', json_encode($users, JSON_PRETTY_PRINT));
        header('Location: dashboard.php?folder=0'); 
        exit;
    } 
}

function delFolder($fid, &$folders, &$files, &$user, &$users){
    foreach($folders as $k => $f){
        if(($f['parent_id'] ?? '0') == $fid) { 
            delFolder($k, $folders, $files, $user, $users); 
            unset($folders[$k]); 
        }
    }
    foreach($files as $k => $f){
        if(($f['folder_id'] ?? '0') == $fid && $f['user_id'] == $user['id']){
            $fp = 'uploads/' . $f['filename']; 
            if(file_exists($fp)) unlink($fp);
            $user['storage_used'] -= $f['size']; 
            unset($files[$k]);
        }
    }
    foreach($users as &$x){ if($x['id'] == $user['id']){ $x = $user; break; } }
}

// Get files & folders
$userFiles = [];
foreach($allFiles as $k => $f){
    if($f['user_id'] == $current_user['id'] && ($f['folder_id'] ?? '0') == $currentFolder && !isset($deletedMembersMap[$k])){
        $f['share_id'] = $k;
        $userFiles[] = $f;
    }
}

$subFolders = [];
foreach($allFolders as $k => $f){
    if(($f['user_id'] ?? '') == $current_user['id'] && ($f['parent_id'] ?? '0') == $currentFolder){
        $subFolders[] = $f;
    }
}

function getFolderSize($fid, $files, $folders, $uid){
    $s = 0;
    foreach($files as $f){ 
        if(($f['folder_id'] ?? '0') == $fid && $f['user_id'] == $uid) $s += $f['size']; 
    }
    foreach($folders as $k => $f){ 
        if(($f['parent_id'] ?? '0') == $fid && ($f['user_id'] ?? '') == $uid) {
            $s += getFolderSize($k, $files, $folders, $uid); 
        }
    }
    return $s;
}

function buildPath($fid, $folders) {
    $path = [];
    $current = $fid;
    $count = 0;
    while($current != '0' && $count < 30) {
        if(isset($folders[$current])) {
            array_unshift($path, [
                'id' => $folders[$current]['id'],
                'name' => $folders[$current]['name']
            ]);
            $current = $folders[$current]['parent_id'] ?? '0';
        } else { break; }
        $count++;
    }
    return $path;
}

$breadcrumbPath = buildPath($currentFolder, $allFolders);
$moveFolders = [];
// Build the folder move list. When moving a folder, JavaScript supplies the source id
// and the server also validates the target; the options below are a complete user tree.
foreach($allFolders as $mk=>$mf){
    if(($mf['user_id']??'')===$current_user['id']) $moveFolders[$mk]=$mf;
}

function fs($b){ 
    if($b>=1073741824) return number_format($b/1073741824,2).' GB'; 
    if($b>=1048576) return number_format($b/1048576,2).' MB'; 
    if($b>=1024) return number_format($b/1024,2).' KB'; 
    return $b.' B'; 
}

function gfi($n){
    $e = strtolower(pathinfo($n, PATHINFO_EXTENSION));
    if(in_array($e, ['jpg','jpeg','png','gif','webp'])) return ['fa-file-image','#60a5fa'];
    if(in_array($e, ['mp4','avi','mkv','webm'])) return ['fa-file-video','#f87171'];
    if(in_array($e, ['mp3','wav','flac'])) return ['fa-file-audio','#fbbf24'];
    if($e == 'pdf') return ['fa-file-pdf','#ef4444'];
    if(in_array($e, ['zip','rar','7z'])) return ['fa-file-zipper','#a78bfa'];
    return ['fa-file','#94a3b8'];
}

$settings = json_decode(file_get_contents('data/settings.json'), true);
$siteName = $settings['site_name'] ?? 'CKM Files';
?>
<!DOCTYPE html>
<html lang="en">
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
        html,body{height:100%;overflow:hidden;}
        body{font-family:'Segoe UI',system-ui,sans-serif;background:#0a0e17;color:#f1f5f9;font-size:13px;display:flex;flex-direction:column;}
        
        /* APP CONTAINER - Full height */
        .app{display:flex;flex-direction:column;height:100vh;overflow:hidden;}
        
        /* TOP BAR */
        .topbar{background:#111827;border-bottom:1px solid #1e293b;padding:0 12px;height:44px;min-height:44px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
        .topbar .brand{font-size:16px;font-weight:800;color:#3b82f6;}
        .topbar .actions{display:flex;gap:4px;}
        .topbar .actions button,.topbar .actions a{width:30px;height:30px;border-radius:50%;border:1px solid #1e293b;background:#0a0e17;color:#94a3b8;cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:12px;}
        .topbar .actions .upload{background:#3b82f6;color:#fff;border:none;}
        
        /* BREADCRUMB */
        .breadcrumb{background:#111827;border-bottom:1px solid #1e293b;padding:4px 12px;font-size:11px;display:flex;align-items:center;overflow-x:auto;flex-shrink:0;min-height:30px;}
        .breadcrumb a{color:#60a5fa;text-decoration:none;padding:2px 6px;}
        .breadcrumb .sep{color:#64748b;margin:0 2px;}
        
        /* CONTENT - Scrollable */
        .content{flex:1;overflow-y:auto;padding:4px 0;}
        
        /* ITEMS */
        .item{display:flex;align-items:center;padding:8px 12px;border-bottom:1px solid #1a2332;cursor:pointer;user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;touch-action:manipulation;}
        .item:hover{background:#111827;}
        .item .icon{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
        .item .icon.folder{background:rgba(59,130,246,0.1);color:#3b82f6;}
        .item .info{flex:1;min-width:0;margin-left:8px;}
        .item .info .name{font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .item .info .meta{font-size:9px;color:#64748b;}
        .item .del{color:#64748b;background:none;border:none;cursor:pointer;font-size:12px;padding:4px;}
        .item .del:hover{color:#ef4444;}
        .item .actions{display:flex;align-items:center;gap:3px;margin-left:4px;position:relative;}
        .item .action-btn{color:#64748b;background:none;border:none;cursor:pointer;font-size:12px;padding:5px;text-decoration:none;}
        .item .action-btn:hover{color:#60a5fa;}
        .item-menu{display:none;position:absolute;right:0;top:28px;z-index:150;min-width:170px;background:#111827;border:1px solid #263244;border-radius:9px;box-shadow:0 12px 28px rgba(0,0,0,.45);overflow:hidden;}
        .item-menu.show{display:block;}
        .item-menu button,.item-menu a{width:100%;display:flex;align-items:center;gap:9px;padding:10px 12px;background:none;border:0;color:#e2e8f0;text-decoration:none;font-size:11px;text-align:left;cursor:pointer;}
        .item-menu button:hover,.item-menu a:hover{background:#1e293b;color:#60a5fa;}
        .item-menu i{width:14px;text-align:center;color:#94a3b8;}
        .item.selected{background:#172554;border-bottom-color:#29477a;}
        .item .select-box{width:22px;height:22px;display:none;align-items:center;justify-content:center;margin-right:5px;flex-shrink:0;}
        .selection-mode .item .select-box{display:flex;}
        .select-box input{width:17px;height:17px;accent-color:#3b82f6;cursor:pointer;}
        .selection-bar{display:none;background:#111827;border-bottom:1px solid #263244;height:44px;padding:0 10px;align-items:center;gap:8px;flex-shrink:0;}
        .selection-bar.show{display:flex;}
        .selection-bar .count{font-size:12px;font-weight:700;flex:1;}
        .selection-bar button{border:0;background:#0a0e17;color:#cbd5e1;border:1px solid #263244;border-radius:7px;width:31px;height:31px;cursor:pointer;}
        .selection-bar button:hover{color:#60a5fa;border-color:#3b82f6;}
        .bulk-menu{display:none;position:fixed;right:10px;top:48px;z-index:500;min-width:180px;background:#111827;border:1px solid #263244;border-radius:9px;box-shadow:0 12px 28px rgba(0,0,0,.5);overflow:hidden;}
        .bulk-menu.show{display:block;}
        .bulk-menu button{width:100%;padding:11px 13px;background:none;border:0;color:#e2e8f0;text-align:left;font-size:11px;display:flex;gap:9px;align-items:center;cursor:pointer;}
        .bulk-menu button:hover{background:#1e293b;color:#60a5fa;}
        .move-select{display:none;position:fixed;z-index:200;left:50%;bottom:0;transform:translateX(-50%);width:100%;max-width:450px;background:#111827;border-top:1px solid #1e293b;padding:14px;box-shadow:0 -10px 30px rgba(0,0,0,.35);}
        .move-select.show{display:block;}
        .move-select h4{font-size:13px;margin-bottom:8px;text-align:center;}
        .move-select select{width:100%;padding:10px;background:#0a0e17;color:#f1f5f9;border:1px solid #1e293b;border-radius:7px;}
        .new-destination{width:100%;margin-top:10px;padding:9px;border:1px dashed #334155;border-radius:7px;background:#0a0e17;color:#60a5fa;font-weight:600;font-size:11px;cursor:pointer}.new-destination:hover{border-color:#3b82f6;background:#111827}.move-actions{display:flex;gap:8px;margin-top:10px}.move-actions button{flex:1;padding:9px;border:0;border-radius:7px;font-weight:600;cursor:pointer}.move-cancel{background:#1e293b;color:#94a3b8}.move-ok{background:#3b82f6;color:#fff}
        
        .empty{text-align:center;padding:40px 20px;color:#64748b;}
        .empty i{font-size:32px;display:block;margin-bottom:8px;}
        
        /* FOOTER inside content */
        
        /* BOTTOM BAR - Fixed at bottom */
        .bottom-bar{background:#111827;border-top:1px solid #1e293b;height:48px;min-height:48px;display:flex;align-items:center;justify-content:space-around;flex-shrink:0;padding:0 4px;}
        .bottom-bar a,.bottom-bar button{display:flex;flex-direction:column;align-items:center;color:#64748b;text-decoration:none;background:none;border:none;font-size:8px;cursor:pointer;padding:4px 8px;}
        .bottom-bar a i,.bottom-bar button i{font-size:14px;margin-bottom:1px;}
        .bottom-bar .active{color:#3b82f6;}
        .bottom-bar .upload-btn{background:#3b82f6;color:#fff;border-radius:16px;flex-direction:row;padding:4px 12px;gap:4px;font-size:10px;font-weight:600;}
        .bottom-bar .upload-btn i{font-size:12px;margin:0;}
        
        /* MODAL */
        .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:600;align-items:flex-end;}
        .modal.show{display:flex;}
        .modal-box{background:#111827;border-radius:14px 14px 0 0;width:100%;max-width:450px;margin:0 auto;padding:16px 18px 22px;}
        .modal-box h3{font-size:14px;text-align:center;margin-bottom:12px;}
        .modal-box .field{margin-bottom:10px;}
        .modal-box .field label{font-size:10px;color:#94a3b8;display:block;margin-bottom:2px;font-weight:600;}
        .modal-box .field input{width:100%;padding:8px 10px;background:#0a0e17;border:1px solid #1e293b;border-radius:6px;color:#f1f5f9;font-size:13px;outline:none;}
        .modal-box .actions{display:flex;gap:8px;margin-top:12px;}
        .modal-box .actions button{flex:1;padding:10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;}
        .modal-box .actions .cancel{background:#1e293b;color:#94a3b8;}
        .modal-box .actions .confirm{background:#3b82f6;color:#fff;}
    </style>
</head>
<body>
<div class="app">
    <!-- TOP BAR -->
    <div class="topbar">
        <div class="brand">CKM Files</div>
        <div class="actions">
            <button onclick="openModal('folderModal')"><i class="fas fa-folder-plus"></i></button>
            <a href="upload.php?folder=<?php echo $currentFolder; ?>" class="upload"><i class="fas fa-plus"></i></a>
        </div>
    </div>
    
    <!-- BULK SELECTION BAR -->
    <div class="selection-bar" id="selectionBar">
        <button type="button" title="Select all" onclick="selectAllVisible()"><i class="fas fa-check-double"></i></button>
        <div class="count"><span id="selectedCount">0</span> selected</div>
        <button type="button" title="More" onclick="toggleBulkMenu()"><i class="fas fa-ellipsis-vertical"></i></button>
        <button type="button" title="Cancel selection" onclick="clearSelection()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="bulk-menu" id="bulkMenu">
        <button onclick="bulkAction('move')"><i class="fas fa-folder-tree"></i> Move</button>
        <button onclick="bulkAction('copy')"><i class="fas fa-copy"></i> Copy</button>
        <button onclick="bulkAction('delete')"><i class="fas fa-trash"></i> Delete</button>
        <button onclick="clearSelection()"><i class="fas fa-xmark"></i> Cancel</button>
    </div>

    <!-- BREADCRUMB -->
    <div class="breadcrumb">
        <a href="dashboard.php?folder=0"><i class="fas fa-home"></i></a>
        <?php foreach($breadcrumbPath as $crumb): ?>
            <span class="sep">/</span>
            <a href="dashboard.php?folder=<?php echo $crumb['id']; ?>"><?php echo htmlspecialchars($crumb['name']); ?></a>
        <?php endforeach; ?>
    </div>
    
    <!-- CONTENT - Scrollable -->
    <div class="content">
        <?php if(count($subFolders)==0 && count($userFiles)==0): ?>
            <div class="empty">
                <i class="fas fa-folder-open"></i>
                <p>Folder is empty</p>
                <p style="font-size:11px;margin-top:4px;">Tap + to upload files</p>
            </div>
        <?php else: ?>
            <?php foreach($subFolders as $f): 
                $fSize = getFolderSize($f['id'], $allFiles, $allFolders, $current_user['id']);
            ?>
            <div class="item" data-kind="folder" data-id="<?php echo htmlspecialchars($f['id'],ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars($f['name'],ENT_QUOTES); ?>" data-type="Folder" onclick="itemClick(this, event, 'dashboard.php?folder=<?php echo $f['id']; ?>')" oncontextmenu="enableSelection(event,this)">
                <div class="select-box"><input type="checkbox" onclick="event.stopPropagation(); updateSelection(this)"></div>
                <div class="icon folder"><i class="fas fa-folder"></i></div>
                <div class="info">
                    <div class="name"><?php echo htmlspecialchars($f['name']); ?></div>
                    <div class="meta"><?php echo $fSize>0 ? fs($fSize) : 'Empty'; ?></div>
                </div>
                <div class="actions" onclick="event.stopPropagation();">
                    <button class="action-btn menu-trigger" title="More options" onclick="toggleMenu(this)"><i class="fas fa-ellipsis-vertical"></i></button>
                    <div class="item-menu">
                        <button onclick="openMove('folder','<?php echo htmlspecialchars($f['id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($f['parent_id']??'0',ENT_QUOTES); ?>')"><i class="fas fa-folder-tree"></i> Move Folder</button>
                        <button onclick="openCopy('folder','<?php echo htmlspecialchars($f['id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($f['parent_id']??'0',ENT_QUOTES); ?>')"><i class="fas fa-copy"></i> Copy Folder</button>
                        <button onclick="copyFolderLink('<?php echo htmlspecialchars($f['id'],ENT_QUOTES); ?>')"><i class="fas fa-link"></i> Copy Link</button>
                        <a href="folder_download.php?id=<?php echo urlencode($f['id']); ?>"><i class="fas fa-file-zipper"></i> Download ZIP</a>
                        <a href="?delete_folder=<?php echo $f['id']; ?>&folder=<?php echo $currentFolder; ?>" onclick="return confirm('Delete this folder and everything inside it?')"><i class="fas fa-trash"></i> Delete Folder</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php foreach(array_reverse($userFiles) as $f): 
                $fi = gfi($f['original_name']);
            ?>
            <div class="item" data-kind="file" data-id="<?php echo htmlspecialchars($f['share_id'],ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars($f['original_name'],ENT_QUOTES); ?>" data-type="<?php echo htmlspecialchars(pathinfo($f['original_name'],PATHINFO_EXTENSION) ?: 'File',ENT_QUOTES); ?>" onclick="itemClick(this, event, 'share.php?id=<?php echo $f['share_id']; ?>')" oncontextmenu="enableSelection(event,this)">
                <div class="select-box"><input type="checkbox" onclick="event.stopPropagation(); updateSelection(this)"></div>
                <div class="icon" style="color:<?php echo $fi[1]; ?>;"><i class="far <?php echo $fi[0]; ?>"></i></div>
                <div class="info">
                    <div class="name"><?php echo htmlspecialchars($f['original_name']); ?></div>
                    <div class="meta"><?php echo fs($f['size']); ?></div>
                </div>
                <div class="actions" onclick="event.stopPropagation();">
                    <button class="action-btn menu-trigger" title="More options" onclick="toggleMenu(this)"><i class="fas fa-ellipsis-vertical"></i></button>
                    <div class="item-menu">
                        <button onclick="openMove('file','<?php echo htmlspecialchars($f['share_id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($f['folder_id']??'0',ENT_QUOTES); ?>')"><i class="fas fa-folder-tree"></i> Move File</button>
                        <button onclick="openCopy('file','<?php echo htmlspecialchars($f['share_id'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($f['folder_id']??'0',ENT_QUOTES); ?>')"><i class="fas fa-copy"></i> Copy File</button>
                        <a href="?delete_file=<?php echo $f['share_id']; ?>&folder=<?php echo $currentFolder; ?>" onclick="return confirm('Delete this file?')"><i class="fas fa-trash"></i> Delete File</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- BOTTOM BAR - Fixed -->
    <div class="bottom-bar">
        <button class="active"><i class="fas fa-folder-open"></i><span>Files</span></button>
        <a href="upload.php?folder=<?php echo $currentFolder; ?>" class="upload-btn"><i class="fas fa-plus"></i> Upload</a>
        <button onclick="location.href='profile.php'"><i class="fas fa-user"></i><span>Profile</span></button>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </div>
</div>

<!-- MODAL -->
<div class="modal" id="folderModal">
    <div class="modal-box">
        <h3>New Folder</h3>
        <form method="POST">
            <input type="hidden" name="parent_id" value="<?php echo $currentFolder; ?>">
            <div class="field">
                <label>Folder Name</label>
                <input type="text" name="folder_name" required>
            </div>
            <div class="actions">
                <button type="button" class="cancel" onclick="closeModal('folderModal')">Cancel</button>
                <button type="submit" name="create_folder" class="confirm">Create</button>
            </div>
        </form>
    </div>
</div>

<!-- DESTINATION NEW FOLDER MODAL -->
<div class="modal" id="destinationFolderModal">
    <div class="modal-box">
        <h3>Create New Destination Folder</h3>
        <div class="field"><label>Folder Name</label><input type="text" id="destinationFolderName" autocomplete="off"></div>
        <div class="actions"><button type="button" class="cancel" onclick="closeModal('destinationFolderModal')">Cancel</button><button type="button" class="confirm" onclick="createDestinationFolder()">Create Folder</button></div>
    </div>
</div>

<!-- MOVE MODAL -->
<div class="move-select" id="moveBox">
    <h4><i class="fas fa-folder-tree"></i> <span id="moveTitle">Move File</span></h4>
    <form method="POST" id="moveForm">
        <input type="hidden" name="file_id" id="moveFileId">
        <input type="hidden" name="folder_id" id="moveFolderId">
        <select name="target_folder" id="moveTarget">
            <option value="0">📁 Root</option>
            <?php foreach($moveFolders as $mf): ?>
                <option value="<?php echo htmlspecialchars($mf['id'],ENT_QUOTES); ?>"><?php echo htmlspecialchars($mf['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="new-destination" onclick="openDestinationFolderModal('move')"><i class="fas fa-folder-plus"></i> Create New Folder</button>
        <div class="move-actions">
            <button type="button" class="move-cancel" onclick="closeMove()">Cancel</button>
            <button type="submit" name="move_file" id="moveSubmitFile" class="move-ok">Move Here</button><button type="submit" name="move_folder" id="moveSubmitFolder" class="move-ok" style="display:none">Move Here</button>
        </div>
    </form>
</div>

<!-- BULK MOVE MODAL -->
<div class="move-select" id="bulkMoveBox">
    <h4><i class="fas fa-folder-tree"></i> <span id="bulkMoveTitle">Move Selected</span></h4>
    <select id="bulkMoveTarget">
        <option value="0">📁 Root</option>
        <?php foreach($moveFolders as $mf): ?>
            <option value="<?php echo htmlspecialchars($mf['id'],ENT_QUOTES); ?>"><?php echo htmlspecialchars($mf['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="new-destination" onclick="openDestinationFolderModal('bulk')"><i class="fas fa-folder-plus"></i> Create New Folder</button>
    <div class="move-actions"><button type="button" class="move-cancel" onclick="closeBulkMove()">Cancel</button><button type="button" class="move-ok" id="bulkDestinationSubmit" onclick="submitBulkMove()">Move Here</button></div>
</div>

<form method="POST" id="bulkForm" style="display:none"><input type="hidden" name="bulk_action" id="bulkAction"><input type="hidden" name="selected_items" id="bulkSelected"><input type="hidden" name="target_folder" id="bulkTarget"></form>

<script>
    let selectionMode=false;
    let longPressTimer=null;
    let selected=new Map();

    function enableSelection(e, el){
        if(e) e.preventDefault();
        selectionMode=true;
        document.querySelector('.content').classList.add('selection-mode');
        syncSelectionItem(el, true);
        updateSelectionUI();
        return false;
    }
    function startLongPress(el){
        clearTimeout(longPressTimer);
        longPressTimer=setTimeout(()=>enableSelection(null,el),550);
    }
    function cancelLongPress(){clearTimeout(longPressTimer);}
    // Long-press selects the item without selecting its text. A normal tap still opens the item.
    document.querySelectorAll('.content .item').forEach(el=>{
        el.addEventListener('pointerdown',function(e){
            if(e.pointerType==='mouse' && e.button!==0) return;
            startLongPress(el);
        });
        ['pointerup','pointercancel','pointerleave'].forEach(evt=>el.addEventListener(evt,cancelLongPress));
        el.addEventListener('contextmenu',function(e){
            e.preventDefault();
            cancelLongPress();
            enableSelection(e,el);
        });
    });

    // When selection mode is active, tapping/clicking an empty area of the screen
    // clears all selected items. Taps on items and selection controls keep working normally.
    document.addEventListener('click',function(e){
        if(!selectionMode) return;
        if(
            e.target.closest('.item') ||
            e.target.closest('#selectionBar') ||
            e.target.closest('#bulkMenu') ||
            e.target.closest('.move-select') ||
            e.target.closest('.modal')
        ) return;
        clearSelection();
    });

    function itemClick(el,e,url){
        if(selectionMode){
            e.preventDefault();
            const cb=el.querySelector('.select-box input'); cb.checked=!cb.checked; updateSelection(cb);
        } else location.href=url;
    }
    function syncSelectionItem(el,on){
        const key=el.dataset.kind+':'+el.dataset.id;
        const cb=el.querySelector('.select-box input');
        if(cb) cb.checked=on;
        if(on) selected.set(key,{kind:el.dataset.kind,id:el.dataset.id,name:el.dataset.name,type:el.dataset.type});
        else selected.delete(key);
        el.classList.toggle('selected',on);
    }
    function updateSelection(cb){
        const el=cb.closest('.item');
        if(!selectionMode){selectionMode=true;document.querySelector('.content').classList.add('selection-mode');}
        syncSelectionItem(el,cb.checked); updateSelectionUI();
    }
    function selectAllVisible(){
        const items=[...document.querySelectorAll('.content .item[data-id]')];
        const allSelected=items.length && items.every(el=>selected.has(el.dataset.kind+':'+el.dataset.id));
        items.forEach(el=>syncSelectionItem(el,!allSelected)); updateSelectionUI();
    }
    function clearSelection(){
        selected.clear(); document.querySelectorAll('.item.selected').forEach(el=>el.classList.remove('selected'));
        document.querySelectorAll('.select-box input').forEach(cb=>cb.checked=false);
        selectionMode=false; document.querySelector('.content').classList.remove('selection-mode');
        document.getElementById('selectionBar').classList.remove('show'); document.getElementById('bulkMenu').classList.remove('show');
    }
    function updateSelectionUI(){
        document.getElementById('selectedCount').textContent=selected.size;
        if(selected.size===0){
            selectionMode=false;
            document.querySelector('.content').classList.remove('selection-mode');
            document.getElementById('selectionBar').classList.remove('show');
        } else {
            document.getElementById('selectionBar').classList.add('show');
        }
    }
    function toggleBulkMenu(){document.getElementById('bulkMenu').classList.toggle('show');}
    function selectedPayload(){return JSON.stringify([...selected.values()].map(x=>({kind:x.kind,id:x.id})));}
    function submitBulk(action,target=''){
        if(!selected.size) return;
        document.getElementById('bulkAction').value=action;
        document.getElementById('bulkSelected').value=selectedPayload();
        document.getElementById('bulkTarget').value=target;
        document.getElementById('bulkForm').submit();
    }
    function bulkAction(action){
        document.getElementById('bulkMenu').classList.remove('show');
        if(action==='delete'){
            if(confirm('Delete '+selected.size+' selected item(s)? This cannot be undone.')) submitBulk('delete');
        } else if(action==='move'){
            const sel=document.getElementById('bulkMoveTarget');
            Array.from(sel.options).forEach(o=>o.hidden=false);
            const ids=new Set([...selected.values()].filter(x=>x.kind==='folder').map(x=>x.id));
            // Hide selected folders and all their descendants as destinations.
            const folders=<?php echo json_encode($allFolders); ?>;
            const blocked=new Set(ids); let changed=true;
            while(changed){changed=false;Object.keys(folders).forEach(k=>{if(blocked.has(String(folders[k].parent_id||'0'))&&!blocked.has(String(k))){blocked.add(String(k));changed=true;}});}
            Array.from(sel.options).forEach(o=>o.hidden=blocked.has(String(o.value)));
            sel.value='0'; document.getElementById('bulkMoveTitle').textContent='Move Selected'; document.getElementById('bulkDestinationSubmit').textContent='Move Here'; document.getElementById('bulkDestinationSubmit').onclick=submitBulkMove; document.getElementById('bulkMoveBox').classList.add('show');
        } else if(action==='copy'){
            const sel=document.getElementById('bulkMoveTarget');
            Array.from(sel.options).forEach(o=>o.hidden=false);
            const ids=new Set([...selected.values()].filter(x=>x.kind==='folder').map(x=>x.id));
            const folders=<?php echo json_encode($allFolders); ?>; const blocked=new Set(ids); let changed=true;
            while(changed){changed=false;Object.keys(folders).forEach(k=>{if(blocked.has(String(folders[k].parent_id||'0'))&&!blocked.has(String(k))){blocked.add(String(k));changed=true;}});}
            Array.from(sel.options).forEach(o=>o.hidden=blocked.has(String(o.value)));
            sel.value='0';
            document.getElementById('bulkMoveTitle').textContent='Copy Selected';
            document.getElementById('bulkDestinationSubmit').textContent='Copy Here';
            document.getElementById('bulkDestinationSubmit').onclick=submitBulkCopy;
            document.getElementById('bulkMoveBox').classList.add('show');
        }
    }
    function submitBulkMove(){submitBulk('move',document.getElementById('bulkMoveTarget').value);}
    function submitBulkCopy(){submitBulk('copy',document.getElementById('bulkMoveTarget').value);}
    function closeBulkMove(){document.getElementById('bulkMoveBox').classList.remove('show');}
    function toggleMenu(btn){
        const menu=btn.parentElement.querySelector('.item-menu');
        document.querySelectorAll('.item-menu.show').forEach(m=>{if(m!==menu)m.classList.remove('show');});
        menu.classList.toggle('show');
    }
    document.addEventListener('click',function(e){if(!e.target.closest('.actions')) document.querySelectorAll('.item-menu.show').forEach(m=>m.classList.remove('show'));});
    function openMove(type,id,current){
        document.getElementById('moveFileId').value='';
        document.getElementById('moveFolderId').value='';
        const select=document.getElementById('moveTarget');
        Array.from(select.options).forEach(o=>o.hidden=false);
        if(type==='folder'){
            document.getElementById('moveFolderId').value=id;
            document.getElementById('moveTitle').textContent='Move Folder';
            document.getElementById('moveSubmitFile').style.display='none';
            document.getElementById('moveSubmitFolder').style.display='block';
            // Never offer the folder itself or any child/descendant as a destination.
            const children=<?php echo json_encode($allFolders); ?>;
            const blocked=new Set([String(id)]);
            let changed=true;
            while(changed){
                changed=false;
                Object.keys(children).forEach(k=>{
                    const parent=String(children[k].parent_id||'0');
                    if(blocked.has(parent) && !blocked.has(String(k))){ blocked.add(String(k)); changed=true; }
                });
            }
            Array.from(select.options).forEach(o=>{ if(blocked.has(String(o.value))) o.hidden=true; });
        } else {
            document.getElementById('moveFileId').value=id;
            document.getElementById('moveTitle').textContent='Move File';
            document.getElementById('moveSubmitFile').style.display='block';
            document.getElementById('moveSubmitFolder').style.display='none';
        }
        const cur=String(current||'0');
        if(!Array.from(select.options).some(o=>String(o.value)===cur && !o.hidden)) select.value='0';
        else select.value=cur;
        document.getElementById('moveBox').classList.add('show');
    }
    function openCopy(type,id,current){
        document.getElementById('moveFileId').value='';
        document.getElementById('moveFolderId').value='';
        const form=document.getElementById('moveForm');
        const select=document.getElementById('moveTarget');
        Array.from(select.options).forEach(o=>o.hidden=false);
        form.querySelectorAll('button[data-copy-submit]').forEach(b=>b.remove());
        if(type==='folder'){
            document.getElementById('moveFolderId').value=id;
            document.getElementById('moveTitle').textContent='Copy Folder';
            document.getElementById('moveSubmitFile').style.display='none';
            document.getElementById('moveSubmitFolder').style.display='none';
            const b=document.createElement('button'); b.type='submit'; b.name='copy_folder'; b.value='1'; b.className='move-ok'; b.dataset.copySubmit='1'; b.textContent='Copy Here';
            form.querySelector('.move-actions').appendChild(b);
            const folders=<?php echo json_encode($allFolders); ?>; const blocked=new Set([String(id)]); let changed=true;
            while(changed){changed=false;Object.keys(folders).forEach(k=>{const parent=String(folders[k].parent_id||'0');if(blocked.has(parent)&&!blocked.has(String(k))){blocked.add(String(k));changed=true;}});}
            Array.from(document.getElementById('moveTarget').options).forEach(o=>o.hidden=blocked.has(String(o.value)));
        } else {
            document.getElementById('moveFileId').value=id;
            document.getElementById('moveTitle').textContent='Copy File';
            document.getElementById('moveSubmitFile').style.display='none';
            document.getElementById('moveSubmitFolder').style.display='none';
            const b=document.createElement('button'); b.type='submit'; b.name='copy_file'; b.value='1'; b.className='move-ok'; b.dataset.copySubmit='1'; b.textContent='Copy Here';
            form.querySelector('.move-actions').appendChild(b);
        }
        if(!Array.from(select.options).some(o=>String(o.value)===String(current||'0')&&!o.hidden)) select.value='0'; else select.value=String(current||'0');
        document.getElementById('moveBox').classList.add('show');
    }
    let destinationMode='move';
    function openDestinationFolderModal(mode){
        document.getElementById('bulkMenu').classList.remove('show');
        destinationMode=mode;
        document.getElementById('destinationFolderName').value='';
        document.getElementById('destinationFolderModal').classList.add('show');
        setTimeout(()=>document.getElementById('destinationFolderName').focus(),80);
    }
    async function createDestinationFolder(){
        const input=document.getElementById('destinationFolderName');
        const name=input.value.trim();
        if(!name){input.focus();return;}
        // The new folder must be created INSIDE the folder currently selected as the destination.
        const targetSelect = destinationMode==='bulk' ? document.getElementById('bulkMoveTarget') : document.getElementById('moveTarget');
        const parentId = targetSelect ? String(targetSelect.value || '0') : '0';
        const fd=new FormData();
        fd.append('ajax_create_folder','1');
        fd.append('folder_name',name);
        fd.append('parent_id',parentId);
        try{
            const r=await fetch('dashboard.php?folder=<?php echo rawurlencode($currentFolder); ?>',{method:'POST',body:fd});
            const data=await r.json();
            if(!data.ok){alert(data.message||'Folder could not be created.');return;}
            const option=document.createElement('option');
            option.value=data.id;
            option.textContent=data.name;
            if(targetSelect){
                targetSelect.appendChild(option);
                targetSelect.value=data.id;
            }
            closeModal('destinationFolderModal');
        }catch(e){alert('Folder create failed. Please try again.');}
    }

    function closeMove(){document.getElementById('moveBox').classList.remove('show');}

    function openModal(id){document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show')); document.getElementById(id).classList.add('show');}
    function closeModal(id){document.getElementById(id).classList.remove('show');}
    document.querySelectorAll('.modal').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');}));
    document.getElementById('bulkMoveBox').addEventListener('click',function(e){if(e.target===this)this.classList.remove('show');});
</script>
</body>
</html>