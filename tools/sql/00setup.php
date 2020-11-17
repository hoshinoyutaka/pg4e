<?php

use \Tsugi\Core\LTIX;
use \Tsugi\Util\LTI;
use \Tsugi\Util\PDOX;
use \Tsugi\Util\U;
use \Tsugi\Util\Mersenne_Twister;

$MAX_UPLOAD_FILE_SIZE = 1024*1024;

require_once "sql_util.php";

// echo("<pre>\n");var_dump($_POST);die('zap');

if ( ! pg4e_user_db_load($LAUNCH) ) return;

$oldgrade = $RESULT->grade;

$admin_user = 'postgres';
$admin_pass = $CFG->psql_root_password ?? false;
$admin_connection = "pgsql:host=localhost;port=5432";

$unique = getUnique($LAUNCH);
$db = getDbName($unique);
$user = getDbUser($unique);
$pass = getDbPass($unique);
$user_connection = $admin_connection . ";dbname=" . $db;

$admin_PDO = false;
$user_PDO = false;

if ( U::get($_POST,'reset') ) {
    $admin_PDO = get_connection($admin_connection, $admin_user, $admin_pass);
    if ( ! $admin_PDO ) {
        $_SESSION['error'] = "Unable to access admin connection";
        header('Location: '.addSession('index.php'));
        return;
    }

    $error = "";
    $sql = "DROP DATABASE $db";
    $stmt = $admin_PDO->queryReturnError($sql);
    if ( ! $stmt->success ) {
        error_log("Sql Failure:".$stmt->errorImplode." ".$sql);
        $error = "SQL Query Error: ".$stmt->errorImplode;
    }

    $sql = "DROP USER $user";
    $stmt = $admin_PDO->queryReturnError($sql);
    if ( ! $stmt->success ) {
        error_log("Sql Failure:".$stmt->errorImplode." ".$sql);
        if ( strlen($error) > 0 ) {
            $error .= ' / ' . $stmt->errorImplode;
        } else {
            $error = "SQL Query Error: ".$stmt->errorImplode;
        }
    }
    if ( strlen($error) > 0 ) {
        $_SESSION['error'] = $error;
        header('Location: '.addSession('index.php'));
        return;
    }

    $_SESSION['success'] = "Database reset";
    header('Location: '.addSession('index.php'));
    return;
}

if ( $dueDate->message ) {
    echo('<p style="color:red;">'.$dueDate->message.'</p>'."\n");
}

$user_PDO = get_connection($user_connection, $user, $pass);

if ( ! $user_PDO && ! $admin_pass ) {
    echo("<p>Not configured to create accounts</p>\n");
    return;
}

if ( ! $user_PDO && $admin_pass ) {
    $admin_PDO = get_connection($admin_connection, $admin_user, $admin_pass);
}

if ( $admin_PDO ) {
    // https://dba.stackexchange.com/questions/45143/check-if-postgresql-database-exists-case-insensitive-way
    // SELECT datname FROM pg_catalog.pg_database WHERE lower(datname) = lower('dbname');
    $row = $admin_PDO->rowDie(
        "SELECT datname FROM pg_catalog.pg_database WHERE datname = :nam",
        array(":nam" => $db)
    );

    // http://wiki.postgresql.org/wiki/Shared_Database_Hosting
    // https://dba.stackexchange.com/questions/17790/created-user-can-access-all-databases-in-postgresql-without-any-grants
    // https://stackoverflow.com/questions/3349136/how-to-prevent-a-user-from-being-able-to-see-other-databases-and-the-tables-from
    // https://dba.stackexchange.com/a/17791/206399
    if ( ! $row ) {
        $user_row = $admin_PDO->rowDie("SELECT * FROM pg_user WHERE usename = '$user'");
        if ( ! $user_row ) {
            echo("<p>Creating database...</p>\n");

            $sqls = array(
                "CREATE ROLE $user NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT LOGIN PASSWORD '$pass' ",
                "CREATE DATABASE $db WITH OWNER $user",
                "REVOKE all ON DATABASE $db FROM public",
                "REVOKE all ON SCHEMA public FROM public",
                "GRANT all ON SCHEMA public TO postgres",
                "GRANT connect ON DATABASE $db TO $user",
            );
            foreach($sqls as $sql) {
                // echo($sql."<br/>\n");
                $stmt = $admin_PDO->queryReturnError($sql);
                if ( ! $stmt->success ) {
                    error_log("Sql Failure:".$stmt->errorImplode." ".$sql);
                    echo("<p>SQL Query Error: ".htmlentities($stmt->errorImplode)."</p>");
                    return false;
                 }
            }
	}

    	// Check again
        $row = $admin_PDO->rowDie(
            "SELECT datname FROM pg_catalog.pg_database WHERE datname = :nam",
            array(":nam" => $db)
        );
	if ( $row ) {
            echo("<p>Database created.</p>\n");
	}
    }
}

// Get that connection after initial create finishes.
if ( ! $user_PDO ) {
    $user_PDO = get_connection($user_connection, $user, $pass);
}

if ( ! $user_PDO ) {
?>
<p>If you are having a problem accessing your database, perhaps a reset will work.
If resetting your database does not work, please contact your instructor.</p>
<p>
<form method="post">
<input type="submit" class="btn btn-danger" name="reset" value="Delete and re-create database"
onclick="return confirm('<?= __('Are you sure?') ?>')">
</form>
</p>
<?php
    return;
}

// Set up the meta table
$meta = pg4e_setup_meta($LAUNCH, $user_PDO);

$user_info = getUserInfo($LAUNCH);

$gradetosend = 1.0;
$debug_log = array();

$retval = LTIX::gradeSend($gradetosend, false, $debug_log);

?>
<h1>Your Database</h1>
<p>
You will need access to a database to use for this course.  This tool creates your database and gives
you an account and password to use to connect to the database.
</p>
<pre>
Host:     <?= $user_info->host ?>
Port:     <?= $user_info->port ?>
Database: <?= $user_info->db ?>
User:     <?= $user_info->user ?>
Password: <span id="pass" style="display:none"><?= $user_info->pass ?></span> (<a href="#" onclick="$('#pass').toggle();return false;">hide/show</a> <a href="#" onclick="copyToClipboard(this, $('#pass').text());return false;">copy</a>)


<?= $user_info->psql ?>
</pre>
<!-- If we have DB credentials no reset should be needed - but if you really want to reset - unhide this form :) -->
<p>
<form method="post" style="display:none;">
<input type="submit" class="btn btn-danger" name="reset" value="Delete and re-create database"
onclick="return confirm('<?= __('If you have database credentials, usually there is no need to reset your database.  Are you sure?') ?>')">
</form>
</p>
<p>You will see a <b>pg4e_meta</b> table in your database that is used
internally by the autograder to pass
information from one assignment to another assignment.  The autograder will
store mysterious stuff in this table and look at it later.  Leave this table alone.
If this table does not exist or you change the data the autograder puts in
this table, the autograder may refuse to grade your assignments, or your database
may be deleted as part of a maintenance process.
</p>
<h2>Technical Detail on Your Database</h2>
<p>
This database is on a shared instance of PostgreSQL. You have enough access to your database
to do the homework for this class, but will not have access to some of the server-wide
database wide tables that contain data about the other users on the server.  This can cause
PostgreSQL clients to complain with errors like the following when they try to access
these server-wide databases and schemas:
<pre>
ERROR:  permission denied for view pg_roles
ERROR: permission denied for pg_database
</pre>
</p>
<p>
In a real production environment, you (or someone) will have a access to the all-powerful
<b>postgres</b> account and most accouts have at least read-access to these cross-database tables.
</p>
<p>
References:
<ul>
<li><a href="https://wiki.postgresql.org/wiki/Shared_Database_Hosting#template1"
target="_blank">Postgres Shared Database Hosting</a></li>
<!--
https://wiki.postgresql.org/wiki/Cloud#Multi-Tenancy
-->
</ul>
</p>
