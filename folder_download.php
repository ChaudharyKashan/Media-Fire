<?php
session_start();
if(!isset($_GET['id'])) die('Folder link is missing.');
$fid = (string)$_GET['id'];
$folders = file_exists('data/folders.json') ? (json_decode(file_get_contents('data/folders.json'), true) ?? []) : [];
$files = file_exists('data/files.json') ? (json_decode(file_get_contents('data/files.json'), true) ?? []) : [];
if(!isset($folders[$fid])) die('Folder not found.');
$owner = $folders[$fid]['user_id'] ?? '';
// Public folder links are intentionally readable without login.
$rootName = preg_replace('/[^\w\-. ]+/u','_', $folders[$fid]['name'] ?? 'Folder');
$zipName = ($rootName ?: 'Folder') . '.zip';

if(!class_exists('ZipArchive')) die('ZIP support is not enabled on this server.');

$tmp = tempnam(sys_get_temp_dir(), 'ckmzip_');
@unlink($tmp);
$zipPath = $tmp . '.zip';
$zip = new ZipArchive();
if($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)!==TRUE) die('Could not create ZIP.');

function addFolderToZip($fid, $prefix, $folders, $files, $zip){
    foreach($folders as $k=>$folder){
        if(($folder['parent_id']??'0')===$fid){
            $name = preg_replace('/[\/\\\\:*?"<>|]+/','_', $folder['name'] ?? 'Folder');
            $dir = $prefix . $name . '/';
            $zip->addEmptyDir($dir);
            addFolderToZip($k, $dir, $folders, $files, $zip);
        }
    }
    foreach($files as $f){
        if(($f['folder_id']??'0')===$fid){
            $path = 'uploads/' . ($f['filename']??'');
            if(is_file($path)){
                $name = preg_replace('/[\/\\\\:*?"<>|]+/','_', $f['original_name'] ?? 'file');
                $zip->addFile($path, $prefix . $name);
            }
        }
    }
}
addFolderToZip($fid, $rootName.'/', $folders, $files, $zip);
$zip->close();

if(!is_file($zipPath)) die('Could not create ZIP.');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.str_replace('"','',$zipName).'"');
header('Content-Length: '.filesize($zipPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($zipPath);
@unlink($zipPath);
exit;
?>
