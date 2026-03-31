#!/usr/bin/env php
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
 * Writes the given revision string to the .dplrev file on the remote host.
 * Returns true on success, false on failure.
 */
function writeRemoteRevision(string $ssh, string $revFile, string $revision): bool
{
    exec("$ssh 'echo " . escapeshellarg($revision) . " > $revFile' 2>/dev/null", $output, $code);
    return $code === 0;
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
; [*] defines defaults inherited by all sections.
; Scalar values (host, user, port, ...) can be overridden per section.
; exclude[] patterns are merged with the section's own exclude[] list.
[*]
    exclude[] = dpl.ini
    exclude[] = .git*
    ; exclude[] = .env
    ; exclude[] = tmp/*

[main]
    host = example.com
    ; port = 22
    ; user =
    path = /var/www/html
    ; ssh_key = ~/.ssh/id_rsa

    ; revision_file = .dplrev

    exclude[] = logs/*

; [production]
;     host = prod.example.com
;     path = /var/www/html
INI;


$iniFile = getcwd() . '/dpl.ini';

$noColor   = in_array('--no-color', $argv);
$autoYes   = in_array('--yes', $argv) || in_array('-y', $argv);

// Parse optional positional argument for section name (default: main)
$section = 'main';
foreach (array_slice($argv, 1) as $arg) {
    if (strlen($arg) > 0 && $arg[0] !== '-') {
        $section = $arg;
        break;
    }
}

if (in_array('--help', $argv) || in_array('-?', $argv)) {
    echo <<<HELP
dpl — a simple SSH/rsync deploy tool

Usage:
  php dpl.php [section] [options]

dpl reads dpl.ini from the current directory to determine the remote host,
path, and transfer settings. It uses the local git repository to calculate
which files have changed since the last deploy and transfers only those files.

Arguments:
  section       Section name from dpl.ini to deploy (default: main).

Options:
  --init        Create a dpl.ini template in the current directory.
  --yes, -y     Auto-accept the confirmation prompt before deploying.
  --no-color    Disable colored output (U/D file markers).
  --help, -?    Show this help screen.

dpl.ini section keys:
  host          Remote host (required).
  path          Remote deploy path (required).
  port          SSH port (default: 22).
  user          SSH user (default: current system user).
  ssh_key       Path to SSH private key (default: SSH agent / ~/.ssh/id_rsa).
  revision_file Name of the remote revision tracking file (default: .dplrev).
  exclude[]     File/dir pattern to exclude from deploy (repeatable).
                Supports wildcards: *.log, .*, vendor/*, composer.*

Examples:
  php dpl.php --init          Create a dpl.ini in the current project.
  php dpl.php                 Deploy changed files to [main] interactively.
  php dpl.php production      Deploy changed files to [production].
  php dpl.php -y --no-color   Deploy without prompts or colors (for CI).

HELP;
    exit(0);
}

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

if ($section === '*') {
    fwrite(STDERR, 'Error: [*] is a defaults section and cannot be deployed to.' . PHP_EOL);
    exit(1);
}

$sectionConfig = $config[$section] ?? [];
if (empty($sectionConfig)) {
    fwrite(STDERR, "Error: Section \"[$section]\" not found in dpl.ini." . PHP_EOL);
    exit(1);
}

// Merge [*] defaults: scalar keys fall back to [*] if not set in the section;
// exclude[] arrays are merged (union of both).
$defaults = $config['*'] ?? [];
if (!empty($defaults)) {
    $defaultExcludes = $defaults['exclude'] ?? [];
    if (!is_array($defaultExcludes)) {
        $defaultExcludes = [$defaultExcludes];
    }
    $sectionExcludes = $sectionConfig['exclude'] ?? [];
    if (!is_array($sectionExcludes)) {
        $sectionExcludes = [$sectionExcludes];
    }
    // Section scalar values take priority; fall back to [*] for missing keys
    $sectionConfig = [
        ...array_filter($defaults, fn($k) => $k !== 'exclude', ARRAY_FILTER_USE_KEY),
        ...array_filter($sectionConfig, fn($k) => $k !== 'exclude', ARRAY_FILTER_USE_KEY),
    ];
    // Merge exclude arrays from both sections
    $sectionConfig['exclude'] = array_values(array_unique([...$defaultExcludes, ...$sectionExcludes]));
}

foreach (['host', 'path'] as $required) {
    if (empty($sectionConfig[$required])) {
        fwrite(STDERR, "Error: \"$required\" is required in [$section] section of dpl.ini." . PHP_EOL);
        exit(1);
    }
}

// which files to exclude from deployment, based on patterns in dpl.ini
$excludePatterns = $sectionConfig['exclude'] ?? [];
if (!is_array($excludePatterns)) {
    $excludePatterns = [$excludePatterns];
}

// always exclude the revision file, regardless of dpl.ini exclude list
$revFileName = $sectionConfig['revision_file'] ?? '.dplrev';
if (!in_array($revFileName, $excludePatterns)) {
    $excludePatterns[] = $revFileName;
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

$host    = $sectionConfig['host'];
$path    = rtrim($sectionConfig['path'], '/');
$port    = (int) ($sectionConfig['port'] ?? 22);
$user    = $sectionConfig['user'] ?? null;
$sshKey  = $sectionConfig['ssh_key'] ?? null;

$sshBase = "ssh -p $port";
if ($sshKey) {
    $sshBase .= " -i $sshKey";
}
$sshDest = $user ? "$user@$host" : $host;
$ssh     = "$sshBase $sshDest";

$revFile = "$path/$revFileName";

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

// check if local revision is the same as remote revision
if ($localRev === $remoteRev) {
    echo 'Local revision is the same as remote revision. Nothing to deploy.' . PHP_EOL;
    exit(0);
}

$uploadFiles = [];
$deleteFiles = [];

if ($remoteRev === '') {
    // No previous deploy — all tracked files are uploads, nothing to delete
    exec('git ls-files 2>/dev/null', $uploadFiles, $code);
} else {
    // Verify the remote revision exists in this repository before diffing
    exec("git cat-file -t $remoteRev 2>/dev/null", $catOutput, $catCode);
    if ($catCode !== 0) {
        fwrite(STDERR, "Error: Remote revision $remoteRev does not exist in this repository." . PHP_EOL);
        fwrite(STDERR, "       Is \"$path\" on $host the right path for this project?" . PHP_EOL);
        exit(1);
    }

    // git diff --name-status gives lines like "M\tfile", "D\tfile", "R90\told\tnew"
    $diffOutput = [];
    exec("git diff --name-status $remoteRev $localRev 2>/dev/null", $diffOutput, $code);
    foreach ($diffOutput as $line) {
        $parts = explode("\t", $line);
        $status = $parts[0][0]; // first char: A, M, D, R, C, ...
        if ($status === 'D') {
            $deleteFiles[] = $parts[1];
        } elseif ($status === 'R') {
            $deleteFiles[] = $parts[1]; // old name → delete
            $uploadFiles[] = $parts[2]; // new name → upload
        } else {
            $uploadFiles[] = $parts[1];
        }
    }
}

if ($code !== 0) {
    fwrite(STDERR, 'Error: Failed to get list of changed files.' . PHP_EOL);
    exit(1);
}

$uploadFiles = filterExcluded($uploadFiles, $excludePatterns);
$deleteFiles = filterExcluded($deleteFiles, $excludePatterns);

if (empty($uploadFiles) && empty($deleteFiles)) {
    echo 'Nothing to deploy.' . PHP_EOL;
    writeRemoteRevision($ssh, $revFile, $localRev);
    exit(0);
}

$green = $noColor ? '' : "\033[32m";
$red   = $noColor ? '' : "\033[31m";
$reset = $noColor ? '' : "\033[0m";

$totalFiles = count($uploadFiles) + count($deleteFiles);
echo "Files to deploy ($totalFiles):" . PHP_EOL;
foreach ($uploadFiles as $file) {
    echo "  {$green}U  $file{$reset}" . PHP_EOL;
}
foreach ($deleteFiles as $file) {
    echo "  {$red}D  $file{$reset}" . PHP_EOL;
}

if ($autoYes) {
    echo PHP_EOL . 'Continue? [Y/n] Y' . PHP_EOL;
} else {
    echo PHP_EOL . 'Continue? [Y/n] ';
    $answer = trim(fgets(STDIN));
    if ($answer !== '' && strtolower($answer) !== 'y' && strtolower($answer) !== 'yes') {
        echo 'Aborted.' . PHP_EOL;
        exit(0);
    }
}

// Upload files
$uploaded = 0;
$uploadFailed = [];
if (!empty($uploadFiles)) {
    // Write the file list to a temp file and pass it to rsync via --files-from
    $tmpFile = tempnam(sys_get_temp_dir(), 'dpl_');
    file_put_contents($tmpFile, implode(PHP_EOL, $uploadFiles) . PHP_EOL);
    $rsync = "rsync -az --files-from=" . escapeshellarg($tmpFile) . " -e " . escapeshellarg($sshBase) . " . $sshDest:$path/ 2>/dev/null";
    exec($rsync, $rsyncOutput, $rsyncCode);
    unlink($tmpFile);
    if ($rsyncCode !== 0) {
        $uploadFailed = $uploadFiles;
    } else {
        $uploaded = count($uploadFiles);
    }
}

// Delete files
$deleted = 0;
$deleteFailed = [];
foreach ($deleteFiles as $file) {
    $remoteFile = escapeshellarg("$path/$file");
    exec("$ssh 'rm -f $remoteFile' 2>/dev/null", $rmOutput, $rmCode);
    if ($rmCode !== 0) {
        $deleteFailed[] = $file;
    } else {
        $deleted++;
    }
}

// Update remote revision if at least uploads succeeded (partial failure still records progress)
if (empty($uploadFailed)) {
    if (!writeRemoteRevision($ssh, $revFile, $localRev)) {
        fwrite(STDERR, "Warning: Deploy done but failed to update remote revision in $revFile." . PHP_EOL);
    }
}

// Summary
echo PHP_EOL . 'Deploy summary:' . PHP_EOL;
echo "  Uploaded : $uploaded" . PHP_EOL;
echo "  Deleted  : $deleted" . PHP_EOL;

if (!empty($uploadFailed)) {
    fwrite(STDERR, PHP_EOL . 'Failed to upload (' . count($uploadFailed) . '):' . PHP_EOL);
    foreach ($uploadFailed as $file) {
        fwrite(STDERR, "  $file" . PHP_EOL);
    }
}
if (!empty($deleteFailed)) {
    fwrite(STDERR, PHP_EOL . 'Failed to delete (' . count($deleteFailed) . '):' . PHP_EOL);
    foreach ($deleteFailed as $file) {
        fwrite(STDERR, "  $file" . PHP_EOL);
    }
}

$exitCode = (!empty($uploadFailed) || !empty($deleteFailed)) ? 1 : 0;
exit($exitCode);
