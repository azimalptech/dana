<?php

declare(strict_types=1);

/**
 * Creates the first superadmin (FR-12.19). There is no UI path to create
 * one — this script is the only way in.
 *
 *   php bin/seed_superadmin.php --login=+99363538839 --password=secret --name="Azim"
 *
 * Omit --password to be prompted instead, which keeps the credential out
 * of your shell history.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Auth\CredentialService;
use Dana\Domain\Models\User;
use Dana\Support\Config;

$basePath = dirname(__DIR__);
$config = Config::load($basePath);
Bootstrap::boot($config);

$args = parseArgs($argv);
$login = $args['login'] ?? prompt('Login (phone number, e.g. +99363538839): ');
$name = $args['name'] ?? 'Superadmin';
$password = $args['password'] ?? promptHidden('Password: ');

if ($login === '' || $password === '') {
    fwrite(STDERR, "Login and password are both required.\n");
    exit(1);
}

$existing = User::query()->where('login', $login)->first();

if ($existing !== null) {
    fwrite(STDERR, "A user with login '{$login}' already exists (role: {$existing->role}).\n");
    exit(1);
}

$credentials = new CredentialService($config->require('APP_CRED_KEY'));
$now = date('Y-m-d H:i:s');

$user = new User();
$user->role = User::ROLE_SUPERADMIN;
// chk_users_center: the superadmin is the only centre-less role.
$user->center_id = null;
$user->classroom_id = null;
$user->login = $login;
$user->password_hash = $credentials->hash($password);
// chk_users_password_ct: staff passwords are hash-only, never
// recoverable (FR-1.11). Reset is the only recovery path.
$user->password_ct = null;
$user->password_iv = null;
$user->password_tag = null;
$user->password_set_at = $now;
$user->full_name = $name;
$user->interface_lang = null;
$user->is_active = true;
$user->created_at = $now;
$user->updated_at = $now;
$user->save();

echo "Superadmin created.\n";
echo "  id:    {$user->id}\n";
echo "  login: {$user->login}\n";
echo "  name:  {$user->full_name}\n";
echo "\nThe password is not stored in readable form and cannot be recovered — only reset.\n";

/** @return array<string, string> */
function parseArgs(array $argv): array
{
    $out = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m) === 1) {
            $out[$m[1]] = $m[2];
        }
    }

    return $out;
}

function prompt(string $label): string
{
    echo $label;

    return trim((string) fgets(STDIN));
}

function promptHidden(string $label): string
{
    echo $label;

    // Windows has no stty; fall back to a visible prompt rather than
    // silently echoing when the caller expected hidden input.
    if (stripos(PHP_OS_FAMILY, 'Windows') === false) {
        shell_exec('stty -echo');
        $value = trim((string) fgets(STDIN));
        shell_exec('stty echo');
        echo "\n";

        return $value;
    }

    return trim((string) fgets(STDIN));
}
