<?php

// cli only
if (PHP_SAPI !== 'cli') {
    exit('Run from CLI only.' . PHP_EOL);
}

// check for PHP version 8.3 or higher
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    exit('Requires PHP 8.3 or higher.' . PHP_EOL);
}

/**
 * Filters a list of files by exclude patterns.
 * Patterns support wildcards (fnmatch-style): *.log, composer.*, .*, vendor/*
 * A pattern without a wildcard that matches a path prefix is treated as a directory: vendor
 */
function filterExcluded(array $files, array $excludePatterns): array
{
    if (empty($excludePatterns)) {
        return $files;
    }
    return array_values(array_filter($files, function (string $file) use ($excludePatterns): bool {
        foreach ($excludePatterns as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            // Match against full path or just the filename
            if (fnmatch($pattern, $file) || fnmatch($pattern, basename($file))) {
                return false;
            }
            // Treat pattern as a directory prefix (e.g. "vendor" or "vendor/")
            $dir = rtrim($pattern, '/') . '/';
            if (strncmp($file, $dir, strlen($dir)) === 0) {
                return false;
            }
        }
        return true;
    }));
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
    exclude[] = .git*
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

$main = $config['main'] ?? [];

foreach (['host', 'path'] as $required) {
    if (empty($main[$required])) {
        fwrite(STDERR, "Error: \"$required\" is required in [main] section of dpl.ini." . PHP_EOL);
        exit(1);
    }
}

// which files to exclude from deployment, based on patterns in dpl.ini
$excludePatterns = $main['exclude'] ?? [];
if (!is_array($excludePatterns)) {
    $excludePatterns = [$excludePatterns];
}

// check git for not being in a repository
$gitOutput = [];
exec('git rev-parse --is-inside-work-tree 2>/dev/null', $gitOutput, $gitCode);
if ($gitCode !== 0 || trim($gitOutput[0] ?? '') !== 'true') {
    fwrite(STDERR, 'Error: Not a git repository. Please run this script in a git repository.' . PHP_EOL);
    exit(1);
}

// check for uncommitted changes
$gitOutput = [];
exec('git status --porcelain 2>/dev/null', $gitOutput, $gitCode);
if ($gitCode !== 0) {
    fwrite(STDERR, 'Error: Failed to check git status.' . PHP_EOL);
    exit(1);
}

// git status --porcelain lines are "XY filename"; extract just the filename
$localFiles = [];
foreach ($gitOutput as $line) {
    $file = trim(substr($line, 3));
    if ($file === '') {
        continue;
    }
    // renamed files are shown as "old -> new"; take the new name
    if (strpos($file, ' -> ') !== false) {
        $file = substr($file, strrpos($file, ' -> ') + 4);
    }
    $localFiles[] = $file;
}

$filesWithChanges = filterExcluded($localFiles, $excludePatterns);
if (!empty($filesWithChanges)) {
    fwrite(STDERR, 'Error: Uncommitted changes detected. Stash or commit before deploying:' . PHP_EOL);
    foreach ($filesWithChanges as $file) {
        fwrite(STDERR, "  $file" . PHP_EOL);
    }
    exit(1);
}

// Check the local directory for git and get the current revision
$gitOutput = [];
exec('git rev-parse HEAD 2>/dev/null', $gitOutput, $gitCode);
$localRev = trim($gitOutput[0] ?? '');
if ($gitCode !== 0 || !preg_match('/^[0-9a-f]{40}$/i', $localRev)) {
    fwrite(STDERR, 'Error: Failed to get local git revision. Is this a git repository?' . PHP_EOL);
    exit(1);
}
echo "Local revision: $localRev" . PHP_EOL;

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

if ($remoteRev === '') {
    // No previous deploy — list all files tracked by git
    exec('git ls-files 2>/dev/null', $changedFiles, $code);
} else {
    // Diff between remote and local revision
    exec("git diff --name-only $remoteRev $localRev 2>/dev/null", $changedFiles, $code);
}

if ($code !== 0) {
    fwrite(STDERR, 'Error: Failed to get list of changed files.' . PHP_EOL);
    exit(1);
}

$changedFiles = filterExcluded($changedFiles, $excludePatterns);

if (empty($changedFiles)) {
    echo 'Nothing to deploy.' . PHP_EOL;
    exit(0);
}

echo 'Files to deploy (' . count($changedFiles) . '):' . PHP_EOL;
foreach ($changedFiles as $file) {
    echo "  $file" . PHP_EOL;
}
