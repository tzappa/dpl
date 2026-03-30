<?php

// cli only
if (PHP_SAPI !== 'cli') {
    exit('Run from CLI only.' . PHP_EOL);
}

// check for PHP version 8.3 or higher
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    exit('Requires PHP 8.3 or higher.' . PHP_EOL);
}

// This template is used when running with --init to create a new dpl.ini file.
// It should be kept up to date with the actual expected format of dpl.ini.
$iniTemplate = <<<INI
[main]
    host = example.com
    ; port = 22
    ; user =
    path = /var/www/html
    ; ssh_key = ~/.ssh/id_rsa

    exclude[] = dpl.ini
    ; exclude[] = .env
    ; exclude[] = tmp/*
INI;


$iniFile = getcwd() . '/dpl.ini';

if (in_array('--init', $argv)) {
    if (file_exists($iniFile)) {
        fwrite(STDERR, 'Error: dpl.ini already exists.' . PHP_EOL);
        exit(1);
    }
    file_put_contents($iniFile, $iniTemplate . PHP_EOL);
    echo 'Created dpl.ini in ' . getcwd() . PHP_EOL;
    exit(0);
}

if (!file_exists($iniFile)) {
    fwrite(STDERR, 'Error: dpl.ini not found. Run with --init to create one.' . PHP_EOL);
    exit(1);
}

$config = parse_ini_file($iniFile, true);
if ($config === false) {
    fwrite(STDERR, 'Error: Failed to parse dpl.ini.' . PHP_EOL);
    exit(1);
}

// Check the local directory for git and get the current revision
exec('git rev-parse HEAD 2>/dev/null', $gitOutput, $gitCode);
$localRev = trim($gitOutput[0] ?? '');
if ($gitCode !== 0 || !preg_match('/^[0-9a-f]{40}$/i', $localRev)) {
    fwrite(STDERR, 'Error: Failed to get local git revision. Is this a git repository?' . PHP_EOL);
    exit(1);
}
echo "Local revision: $localRev" . PHP_EOL;

$main = $config['main'] ?? [];

foreach (['host', 'path'] as $required) {
    if (empty($main[$required])) {
        fwrite(STDERR, "Error: \"$required\" is required in [main] section of dpl.ini." . PHP_EOL);
        exit(1);
    }
}

$host    = $main['host'];
$path    = rtrim($main['path'], '/');
$port    = (int) ($main['port'] ?? 22);
$user    = $main['user'] ?? null;
$sshKey  = $main['ssh_key'] ?? null;

$ssh = "ssh -p $port";
if ($sshKey) {
    $ssh .= " -i $sshKey";
}
if ($user) {
    $ssh .= " $user@$host";
} else {
    $ssh .= " $host";
}

$revFile = "$path/.dplrev";

exec("{$ssh} 'test -d $path && test -w $path' 2>/dev/null", $output, $code);
if ($code !== 0) {
    fwrite(STDERR, "Error: Cannot access $path on $host." . PHP_EOL);
    exit(1);
}

exec("{$ssh} 'test -f $revFile' 2>/dev/null", $output, $code);
if ($code !== 0) {
    exec("{$ssh} 'touch $revFile' 2>/dev/null", $output, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Error: Cannot create $revFile on $host." . PHP_EOL);
        exit(1);
    }
} elseif (exec("{$ssh} 'test -r $revFile && test -w $revFile' 2>/dev/null", $output, $code) === false || $code !== 0) {
    fwrite(STDERR, "Error: Cannot read/write $revFile on $host." . PHP_EOL);
    exit(1);
}

$revOutput = [];
exec("{$ssh} 'cat $revFile' 2>/dev/null", $revOutput, $code);
if ($code !== 0) {
    fwrite(STDERR, "Error: Cannot read $revFile on $host." . PHP_EOL);
    exit(1);
}

$remoteRev = trim($revOutput[0] ?? '');
if ($remoteRev !== '' && !preg_match('/^[0-9a-f]{32,40}$/i', $remoteRev)) {
    fwrite(STDERR, "Error: Unexpected content in $revFile: \"$remoteRev\"." . PHP_EOL);
    exit(1);
}

echo 'Remote revision: ' . ($remoteRev ?: '(none)') . PHP_EOL;
