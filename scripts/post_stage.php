<?php
/* The script post_stage.phpwill be executed after the staging process ends. This will allow
 * users to perform some actions on the source tree or server before an attempt to
 * activate the app is made. For example, this will allow creating a new DB schema
 * and modifying some file or directory permissions on staged source files
 * The following environment variables are accessable to the script:
 * 
 * - ZS_RUN_ONCE_NODE - a Boolean flag stating whether the current node is
 *   flagged to handle "Run Once" actions. In a cluster, this flag will only be set when
 *   the script is executed on once cluster member, which will allow users to write
 *   code that is only executed once per cluster for all different hook scripts. One example
 *   for such code is setting up the database schema or modifying it. In a
 *   single-server setup, this flag will always be set.
 * - ZS_WEBSERVER_TYPE - will contain a code representing the web server type
 *   ("IIS" or "APACHE")
 * - ZS_WEBSERVER_VERSION - will contain the web server version
 * - ZS_WEBSERVER_UID - will contain the web server user id
 * - ZS_WEBSERVER_GID - will contain the web server user group id
 * - ZS_PHP_VERSION - will contain the PHP version Zend Server uses
 * - ZS_APPLICATION_BASE_DIR - will contain the directory to which the deployed
 *   application is staged.
 * - ZS_CURRENT_APP_VERSION - will contain the version number of the application
 *   being installed, as it is specified in the package descriptor file
 * - ZS_PREVIOUS_APP_VERSION - will contain the previous version of the application
 *   being updated, if any. If this is a new installation, this variable will be
 *   empty. This is useful to detect update scenarios and handle upgrades / downgrades
 *   in hook scripts
 * - ZS_<PARAMNAME> - will contain value of parameter defined in deployment.xml, as specified by
 *   user during deployment.
 */  

ini_set ( "display_errros", 1 );
ini_set ( "error_reporting", E_ALL );

$appDir = getenv ( "ZS_APPLICATION_BASE_DIR" );
if (! $appDir) {
    echo ("ZS_APPLICATION_BASE_DIR is undefined");
    exit ( 1 );
}

$dbHost = getenv ( "ZS_MY_HOST" );
if (! $dbHost) {
    echo ("ZS_MY_HOST is undefined");
    exit ( 1 );
}
$dbPort = getenv ( "ZS_MY_PORT" );
if (! $dbPort) {
    echo ("ZS_MY_PORT is undefined");
    exit ( 1 );
}
$dbUser = getenv ( "ZS_MY_USER" );
if (! $dbUser) {
    echo ("ZS_MY_USER is undefined");
    exit ( 1 );
}
$dbPassword = getenv ( "ZS_MY_PASSWD" );
if (! $dbPassword) {
    $dbPassword = '';
}

$link = mysqli_connect($dbHost, $dbUser, $dbPassword);
if (mysqli_connect_errno()) {
    echo ('Could not connect: ' . mysqli_connect_error());
    exit ( 1 );
}

$httpAuthPart = '';
$httpAuth = getenv ( "ZS_MY_AUTH" );
if ($httpAuth) {
    echo ("Will create files for Basic Auth. ");

    $httpUser = getenv ( "ZS_MY_AUTH_USER" );
    $httpPassword = getenv ( "ZS_MY_AUTH_PW" );

    $randomA = crypt ( microtime (), rand ( 500, 1000 ) );
    $randomB = crypt ( microtime (), rand ( 500, 1000 ) );

    $authFile = substr ( base64_encode ( $randomA ), 0, 10 );
    $fakeFile = substr ( base64_encode ( $randomB ), 0, 10 );

    $authPasswordCrypt = base64_encode( sha1( $httpPassword, true ) );

    file_put_contents ( "$appDir/.$authFile", $httpUser . ':{SHA}' . $authPasswordCrypt );
    chmod ( "$appDir/.$authFile", 0444 );

    $httpAuthPart = <<<EOP

AuthType Basic
AuthName "PMA Restricted Area"
AuthUserFile "$appDir/.$authFile"
Require valid-user


<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteRule .$authFile $fakeFile
	RewriteRule config.inc.php $fakeFile
</IfModule>

EOP;
}

$passwordConfig = '$cfg[\'Servers\'][$i][\'auth_type\'] = \'cookie\';';
$noPassword = getenv ( "ZS_NO_PASSWORD" );
if ($noPassword) {
    $passwordConfig = '
$cfg[\'Servers\'][$i][\'auth_type\'] = \'config\';
$cfg[\'Servers\'][$i][\'AllowNoPassword\'] = true;';
}

$ini = parse_ini_file(get_cfg_var('zend.install_dir') . DIRECTORY_SEPARATOR . 'gui' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'zs_ui.ini');
$zendDefaultPort = (isset($ini['zend_gui.defaultPort'])) ? $ini['zend_gui.defaultPort'] : '10081';
$zendSecuredPort = (isset($ini['zend_gui.securedPort'])) ? $ini['zend_gui.securedPort'] : '10082';

$template = <<<'EOC'
<?php

$i = 0;
$i++;
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['extension'] = 'mysql';
#PASSWORD_CONFIG#

$cfg['Servers'][$i]['host'] = '#HOST#';
$cfg['Servers'][$i]['port'] = '#PORT#';
$cfg['Servers'][$i]['user'] = '#USER#';
$cfg['Servers'][$i]['password'] = '#PASS#';


$cfg['CheckConfigurationPermissions'] = false;

$zendDefaultPort = '#DEFAULTPORT#';
$zendSecuredPort = '#SECUREDPORT#';

if (! empty($_SERVER['HTTP_HOST'])) {
	$host = $_SERVER['HTTP_HOST'];
	$cfg['CSPAllow'] = "$host:$zendDefaultPort $host:$zendSecuredPort";
}
?>
EOC;

$placeholders = array (
    '#HOST#',
    '#PORT#',
    '#USER#',
    '#PASS#',
    '#DEFAULTPORT#',
    '#SECUREDPORT#',
    '#PASSWORD_CONFIG#',
);
$params = array (
    $dbHost,
    $dbPort,
    $dbUser,
    $dbPassword,
    $zendDefaultPort,
    $zendSecuredPort,
    $passwordConfig,
);
$configuration = str_replace ( $placeholders, $params, $template );

$htaccessText = <<<EOT
Order allow,deny
Allow from all
Options -Indexes
DirectoryIndex index.php
$httpAuthPart
EOT;

file_put_contents ( "$appDir/.htaccess", "$htaccessText" );

file_put_contents ( "$appDir/config.inc.php", "$configuration" );
chmod ( "$appDir/config.inc.php", 0666 );
