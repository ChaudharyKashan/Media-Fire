<?php
session_start();
if(!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }

if(!file_exists('uploads')) mkdir('uploads', 0755, true);
if(!file_exists('data')) mkdir('data', 0755, true);
if(!file_exists('data/files.json')) file_put_contents('data/files.json', '[]');
if(!file_exists('data/users.json')) file_put_contents('data/users.json', '[]');

$users = json_decode(file_get_contents('data/users.json'), true) ?? [];
$current_user = null;
foreach($users as &$u) { if($u['id']==$_SESSION['user_id']) { $current_user=&$u; break; } }
if(($current_user['status']??'active')=='blocked') { echo 'Your account is blocked.'; exit; }
$currentFolder = isset($_GET['folder']) ? $_GET['folder'] : '0';
function fs($b){if($b>=1073741824)return number_format($b/1073741824,2).' GB';if($b>=1048576)return number_format($b/1048576,2).' MB';if($b>=1024)return number_format($b/1024,2).' KB';return $b.' B';}
$settings = json_decode(file_get_contents('data/settings.json'), true);
$siteName = $settings['site_name'] ?? 'CKM Files';
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="description" content="Chaudhary Kashan Meyo (MediaFire) — secure file and folder sharing platform for uploading, organizing, sharing and downloading files.">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="author" content="Chaudhary Kashan Meyo"><link rel="icon" type="image/png" sizes="32x32" href="favicon.png?v=20260913-2">
<link rel="icon" type="image/x-icon" href="favicon.ico?v=20260913-2">
<link rel="shortcut icon" href="favicon.ico?v=20260913-2">
<link rel="apple-touch-icon" href="favicon.png?v=20260913-2">
    <meta name="theme-color" content="#0a0e17">
<title>Chaudhary Kashan Meyo (MediaFire)</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}body{font-family:'Segoe UI',sans-serif;background:#0a0e17;color:#f1f5f9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.container{width:100%;max-width:400px}.back{color:#94a3b8;text-decoration:none;font-size:12px;display:inline-block;margin-bottom:12px}.card{background:#111827;border:1px solid #1e293b;border-radius:14px;padding:24px 20px;text-align:center}.card .icon{font-size:32px;color:#3b82f6;margin-bottom:8px}.card h2{font-size:17px;margin-bottom:4px}.card .sub{font-size:12px;color:#64748b;margin-bottom:16px}.drop{border:2px dashed #1e293b;border-radius:12px;padding:30px 14px;cursor:pointer;background:#0a0e17;margin-bottom:12px}.drop:hover{border-color:#3b82f6}.drop i{font-size:28px;color:#3b82f6;display:block;margin-bottom:4px}.drop p{font-size:13px;font-weight:600}.drop span{font-size:10px;color:#64748b}.selected{display:none;background:#0a0e17;border:1px solid #1e293b;border-radius:8px;padding:10px 12px;margin-bottom:12px;text-align:left}.selected .row{display:flex;align-items:center;gap:10px}.selected .row .info{flex:1}.selected .name{font-size:12px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.selected .size{font-size:10px;color:#64748b}.cancel{background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px}.btn{width:100%;padding:12px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}.btn:disabled{opacity:.55;cursor:not-allowed}.progressBox{display:none;text-align:left;background:#0a0e17;border:1px solid #1e293b;border-radius:10px;padding:12px;margin-top:12px}.progressTop{display:flex;justify-content:space-between;font-size:11px;margin-bottom:7px}.progressPct{font-weight:700;color:#60a5fa}.bar{height:8px;background:#1e293b;border-radius:99px;overflow:hidden}.fill{height:100%;width:0;background:linear-gradient(90deg,#2563eb,#22c55e);transition:width .15s}.status{font-size:10px;color:#94a3b8;margin-top:7px}.success{display:none;padding:10px 0}.success .icon{font-size:40px;color:#22c55e}.success h3{font-size:16px;margin:4px 0}.success p{font-size:11px;color:#64748b;margin-bottom:12px}.success .btns{display:flex;gap:8px}.success .btns a{flex:1;padding:10px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;text-align:center}.outline{background:transparent;border:1px solid #1e293b;color:#f1f5f9}.primary{background:#3b82f6;color:#fff}.err{color:#ef4444;font-size:12px;margin-bottom:10px}.resume{display:none;color:#fbbf24;background:#1c1917;border:1px solid #78350f;padding:9px;border-radius:8px;font-size:10px;margin-bottom:10px;text-align:left}
</style></head>
<body><div class="container">
<a href="dashboard.php?folder=<?php echo urlencode($currentFolder); ?>" class="back"><i class="fas fa-arrow-left"></i> Back</a>
<div class="card" id="uploadCard">
<div id="uploadView"><div class="icon"><i class="fas fa-cloud-upload-alt"></i></div><h2>Upload File</h2><p class="sub">Max 1GB per file • Resumable Upload</p>
<div class="resume" id="resumeNote"><i class="fas fa-info-circle"></i> A previous upload can continue from where it stopped. Select the same file again.</div>
<div class="drop" id="drop" onclick="document.getElementById('fileInput').click()"><i class="fas fa-cloud-upload-alt"></i><p>Click or Drag & Drop</p><span>Any file type supported</span></div>
<input type="file" id="fileInput" style="display:none" multiple onchange="showSelected(this)">
<div class="selected" id="selectedBox"><div id="selectedList"></div></div>
<button type="button" class="btn" id="uploadBtn" onclick="startUpload()"><i class="fas fa-upload"></i> Upload</button>
<div class="progressBox" id="progressBox"><div class="progressTop"><span id="progressText">Uploading…</span><span class="progressPct" id="progressPct">0%</span></div><div class="bar"><div class="fill" id="progressFill"></div></div><div class="status" id="progressStatus">Preparing upload…</div></div>
</div>
<div class="success" id="successView"><div class="icon"><i class="fas fa-check-circle"></i></div><h3>Uploaded!</h3><p id="successInfo"></p><div class="btns"><a id="shareBtn" target="_blank" class="outline"><i class="fas fa-share-alt"></i> Share</a><a href="dashboard.php?folder=<?php echo urlencode($currentFolder); ?>" class="primary"><i class="fas fa-folder-open"></i> Files</a></div></div>
</div></div>
<script>
const MAX=1073741824, CHUNK=8*1024*1024, FOLDER=<?php echo json_encode($currentFolder); ?>;
let files=[], cancelled=false, activeCount=0;
const $=id=>document.getElementById(id);
function human(n){if(n>=1073741824)return (n/1073741824).toFixed(2)+' GB';if(n>=1048576)return (n/1048576).toFixed(2)+' MB';if(n>=1024)return (n/1024).toFixed(1)+' KB';return n+' B'}
function uploadKey(file){return 'ckm_upload_'+FOLDER+'_'+file.name+'_'+file.size+'_'+file.lastModified}
function renderSelected(){
  const box=$('selectedBox'), list=$('selectedList');
  if(!files.length){box.style.display='none';list.innerHTML='';return;}
  box.style.display='block'; list.innerHTML='';
  files.forEach((f,i)=>{const row=document.createElement('div');row.className='row';row.style.marginBottom='8px';row.innerHTML='<div class="info"><div class="name"></div><div class="size"></div></div><button type="button" class="cancel" data-i="'+i+'"><i class="fas fa-times"></i></button>';row.querySelector('.name').textContent=f.name;row.querySelector('.size').textContent=human(f.size);row.querySelector('.cancel').onclick=()=>{if(activeCount===0){files.splice(i,1);renderSelected();}};list.appendChild(row);});
}
function showSelected(input){
  const picked=Array.from(input.files||[]); if(!picked.length)return;
  const invalid=picked.find(f=>f.size>MAX); if(invalid){alert('File too large: '+invalid.name+' (maximum 1 GB per file).');return;}
  const seen=new Set(files.map(f=>f.name+'|'+f.size+'|'+f.lastModified));
  picked.forEach(f=>{const k=f.name+'|'+f.size+'|'+f.lastModified;if(!seen.has(k)){files.push(f);seen.add(k);}});
  $('resumeNote').style.display='none'; renderSelected(); input.value='';
}
$('drop').addEventListener('dragover',e=>{e.preventDefault();$('drop').style.borderColor='#3b82f6'});
$('drop').addEventListener('dragleave',()=>{$('drop').style.borderColor='#1e293b'});
$('drop').addEventListener('drop',e=>{e.preventDefault();$('drop').style.borderColor='#1e293b';const picked=Array.from(e.dataTransfer.files||[]);if(picked.length)showSelected({files:picked});});
function xhrPost(url,form,onProgress){return new Promise((resolve,reject)=>{const x=new XMLHttpRequest();x.open('POST',url,true);x.onload=()=>{try{const j=JSON.parse(x.responseText);if(x.status>=200&&x.status<300&&j.ok)resolve(j);else reject(new Error(j.error||'Server error'))}catch(e){reject(new Error('Invalid server response'))}};x.onerror=()=>reject(new Error('Network error'));x.ontimeout=()=>reject(new Error('Request timed out'));x.timeout=0;if(onProgress)x.upload.onprogress=onProgress;x.send(form)})}
async function initUpload(file){const fd=new FormData();fd.append('action','init');fd.append('name',file.name);fd.append('size',file.size);fd.append('type',file.type);fd.append('folder_id',FOLDER);return xhrPost('upload_api.php',fd)}
async function getState(file){try{const s=JSON.parse(localStorage.getItem(uploadKey(file))||'null');if(!s)return null;const fd=new FormData();fd.append('action','status');fd.append('upload_id',s.uploadId);const r=await xhrPost('upload_api.php',fd);s.offset=r.offset||0;return s}catch(e){return null}}
function saveState(file,uploadId,offset){localStorage.setItem(uploadKey(file),JSON.stringify({uploadId,offset}))}
function clearState(file){localStorage.removeItem(uploadKey(file))}
let fileProgress=[];
function setOverallProgress(status){
  const total=files.reduce((sum,f)=>sum+f.size,0)||1;
  const done=fileProgress.reduce((sum,n)=>sum+n,0);
  const pct=Math.min(100,Math.floor(done/total*100));
  $('progressPct').textContent=pct+'%';$('progressFill').style.width=pct+'%';
  $('progressStatus').textContent=status||('Uploading '+files.length+' file(s) • '+human(done)+' / '+human(total)+' • '+pct+'%');
}
async function sendChunk(file,uploadId,start,index){
  const end=Math.min(start+CHUNK,file.size), chunk=file.slice(start,end);
  const fd=new FormData();fd.append('action','chunk');fd.append('upload_id',uploadId);fd.append('offset',start);fd.append('chunk',chunk,file.name+'.part');
  return xhrPost('upload_api.php',fd,e=>{
    const loaded=Math.min(e.loaded||0,end-start);
    fileProgress[index]=start+loaded;
    setOverallProgress('Uploading '+files.length+' file(s) • '+(index+1)+' active file progress • '+Math.floor((start+loaded)/file.size*100)+'%');
  });
}
async function uploadOne(file,index){
  let state=await getState(file), uploadId=state?state.uploadId:null, offset=state?state.offset:0;
  if(!uploadId){const init=await initUpload(file);uploadId=init.upload_id;offset=0;saveState(file,uploadId,offset)}
  fileProgress[index]=offset;setOverallProgress('Preparing '+(index+1)+' of '+files.length+' • '+Math.floor(offset/file.size*100)+'%');
  while(offset<file.size&&!cancelled){const r=await sendChunk(file,uploadId,offset,index);offset=r.offset;fileProgress[index]=offset;saveState(file,uploadId,offset);setOverallProgress();}
  if(cancelled)return null;
  const fd=new FormData();fd.append('action','complete');fd.append('upload_id',uploadId);const done=await xhrPost('upload_api.php',fd);clearState(file);fileProgress[index]=file.size;setOverallProgress();return done;
}
async function startUpload(){
  if(!files.length)return alert('Please select at least one file first.');cancelled=false;activeCount=1;$('uploadBtn').disabled=true;$('fileInput').disabled=true;$('progressBox').style.display='block';$('progressText').textContent='Uploading '+files.length+' file(s)…';fileProgress=files.map(()=>0);
  try{
    const completed=[];let next=0;const worker=async()=>{while(!cancelled){const i=next++;if(i>=files.length)break;const result=await uploadOne(files[i],i);if(result)completed.push({file:files[i],id:result.id});}};
    const workerCount=Math.min(3,files.length);await Promise.all(Array.from({length:workerCount},worker));
    if(cancelled)return;
    $('progressPct').textContent='100%';$('progressFill').style.width='100%';$('progressStatus').textContent='All '+completed.length+' file(s) uploaded successfully.';
    $('uploadView').style.display='none';$('successView').style.display='block';$('successInfo').textContent=completed.length+' file(s) uploaded successfully.';
    $('shareBtn').href=completed.length===1?'share.php?id='+encodeURIComponent(completed[0].id):'dashboard.php?folder='+encodeURIComponent(FOLDER);
  }catch(e){$('progressStatus').textContent='Paused: '+e.message+'. You can reopen the page and select the same files to resume.';$('uploadBtn').disabled=false;$('fileInput').disabled=false;$('resumeNote').style.display='block';}
  finally{activeCount=0;}
}
window.addEventListener('beforeunload',()=>{});
</script></body></html>
