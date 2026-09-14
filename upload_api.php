<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
function out($a,$code=200){http_response_code($code);echo json_encode($a);exit;}
if(!isset($_SESSION['user_id'])) out(['ok'=>false,'error'=>'Unauthorized'],401);
if(!is_dir('data/uploads_tmp')) mkdir('data/uploads_tmp',0755,true);
if(!is_dir('uploads')) mkdir('uploads',0755,true);
$users=json_decode(file_get_contents('data/users.json'),true)??[];$user=null;
foreach($users as $u){if($u['id']===$_SESSION['user_id']){$user=$u;break;}}
if(!$user || ($user['status']??'active')==='blocked') out(['ok'=>false,'error'=>'Account blocked'],403);
$action=$_POST['action']??''; $max=1073741824; $chunkMax=8*1024*1024+1048576;
function safeId($id){return is_string($id)&&preg_match('/^[a-f0-9]{32}$/',$id);}
function statePath($id){return 'data/uploads_tmp/'.$id.'.json';}
function partPath($id){return 'data/uploads_tmp/'.$id.'.part';}
function loadState($id){$p=statePath($id);if(!safeId($id)||!file_exists($p))out(['ok'=>false,'error'=>'Upload session not found'],404);$s=json_decode(file_get_contents($p),true);if(!$s)out(['ok'=>false,'error'=>'Invalid upload session'],400);if($s['user_id']!==$_SESSION['user_id'])out(['ok'=>false,'error'=>'Forbidden'],403);return $s;}
if($action==='init'){
  $name=basename($_POST['name']??'');$size=(int)($_POST['size']??0);$type=substr((string)($_POST['type']??'application/octet-stream'),0,200);$folder=(string)($_POST['folder_id']??'0');
  if($name===''||$size<1||$size>$max)out(['ok'=>false,'error'=>'Invalid file or file too large'],400);
  $id=md5(uniqid('',true).random_bytes(16));$ext=pathinfo($name,PATHINFO_EXTENSION);$stored=uniqid('',true).'_'.time().($ext!==''?'.'.$ext:'');
  $s=['id'=>$id,'user_id'=>$_SESSION['user_id'],'original_name'=>$name,'size'=>$size,'type'=>$type,'folder_id'=>$folder,'stored_name'=>$stored,'offset'=>0,'created'=>time()];
  file_put_contents(statePath($id),json_encode($s),LOCK_EX);file_put_contents(partPath($id),'');out(['ok'=>true,'upload_id'=>$id,'offset'=>0]);
}
if($action==='status'){$s=loadState($_POST['upload_id']??'');$actual=file_exists(partPath($s['id']))?filesize(partPath($s['id'])):0;if($actual!=$s['offset']){$s['offset']=$actual;file_put_contents(statePath($s['id']),json_encode($s),LOCK_EX);}out(['ok'=>true,'offset'=>$s['offset']]);}
if($action==='chunk'){
  $s=loadState($_POST['upload_id']??'');$offset=(int)($_POST['offset']??-1);if($offset!==$s['offset'])out(['ok'=>false,'error'=>'Offset mismatch. Resume status first.'],409);
  if(!isset($_FILES['chunk'])||$_FILES['chunk']['error']!==UPLOAD_ERR_OK)out(['ok'=>false,'error'=>'Chunk upload failed'],400);
  $tmp=$_FILES['chunk']['tmp_name'];$len=(int)$_FILES['chunk']['size'];if($len<1||$len>$chunkMax)out(['ok'=>false,'error'=>'Invalid chunk size'],400);if($offset+$len>$s['size'])out(['ok'=>false,'error'=>'Chunk exceeds file size'],400);
  $in=fopen($tmp,'rb');$outf=fopen(partPath($s['id']),'ab');if(!$in||!$outf)out(['ok'=>false,'error'=>'Storage error'],500);while(!feof($in)){$buf=fread($in,1048576);if($buf===false)break;fwrite($outf,$buf);}fclose($in);fflush($outf);fclose($outf);
  $s['offset']=filesize(partPath($s['id']));file_put_contents(statePath($s['id']),json_encode($s),LOCK_EX);out(['ok'=>true,'offset'=>$s['offset']]);
}
if($action==='complete'){
  $s=loadState($_POST['upload_id']??'');if($s['offset']!==$s['size'])out(['ok'=>false,'error'=>'Upload is incomplete'],400);$target='uploads/'.$s['stored_name'];if(!rename(partPath($s['id']),$target))out(['ok'=>false,'error'=>'Could not finalize file'],500);
  $all=json_decode(file_get_contents('data/files.json'),true)??[];$fid=substr(md5(uniqid('',true).random_bytes(8)),0,10);$uploaderUsername=$user['username']??'Unknown';$uploaderEmail=$user['email']??'';$all[$fid]=['user_id'=>$s['user_id'],'username'=>$uploaderUsername,'email'=>$uploaderEmail,'uploaded_by'=>'member','original_name'=>$s['original_name'],'filename'=>$s['stored_name'],'size'=>$s['size'],'type'=>$s['type'],'folder_id'=>$s['folder_id'],'date'=>date('Y-m-d H:i:s'),'downloads'=>0];file_put_contents('data/files.json',json_encode($all,JSON_PRETTY_PRINT),LOCK_EX);
  $users=json_decode(file_get_contents('data/users.json'),true)??[];foreach($users as &$u){if($u['id']===$s['user_id']){$u['storage_used']=($u['storage_used']??0)+$s['size'];break;}}file_put_contents('data/users.json',json_encode($users,JSON_PRETTY_PRINT),LOCK_EX);@unlink(statePath($s['id']));out(['ok'=>true,'id'=>$fid]);
}
out(['ok'=>false,'error'=>'Unknown action'],400);
