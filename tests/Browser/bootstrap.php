<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Environment initialization script for functional tests              |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 +-----------------------------------------------------------------------+
*/

if (php_sapi_name() != 'cli')
  die("Not in shell mode (php-cli)");

if (!defined('INSTALL_PATH')) define('INSTALL_PATH', realpath(__DIR__ . '/../../') . '/' );

require_once(INSTALL_PATH . 'program/include/iniset.php');

$rcmail = rcmail::get_instance(0, 'test');

define('TESTS_DIR', realpath(__DIR__) . '/');
define('TESTS_USER', $rcmail->config->get('tests_username'));
define('TESTS_PASS', $rcmail->config->get('tests_password'));

require_once(__DIR__ . '/DuskTestCase.php');


/**
 * Utilities for test environment setup
 */
class bootstrap
{
    static $imap_ready = null;

    /**
     * Wipe and re-initialize database
     */
    public static function init_db()
    {
        $rcmail = rcmail::get_instance();
        $dsn = rcube_db::parse_dsn($rcmail->config->get('db_dsnw'));

passthru('ls -l ' . INSTALL_PATH . 'config');
passthru('cat  ' . INSTALL_PATH . 'config/config-test.inc.php');
passthru('ls -l /tmp');
print_r($dsn);
die;

        if ($dsn['phptype'] == 'mysql' || $dsn['phptype'] == 'mysqli') {
            // drop all existing tables first
            $db = $rcmail->get_dbh();
            $db->query("SET FOREIGN_KEY_CHECKS=0");
            $sql_res = $db->query("SHOW TABLES");
            while ($sql_arr = $db->fetch_array($sql_res)) {
                $table = reset($sql_arr);
                $db->query("DROP TABLE $table");
            }

            // init database with schema
            system(sprintf('cat %s %s | mysql -h %s -u %s --password=%s %s',
                realpath(INSTALL_PATH . '/SQL/mysql.initial.sql'),
                realpath(TESTS_DIR . 'data/mysql.sql'),
                escapeshellarg($dsn['hostspec']),
                escapeshellarg($dsn['username']),
                escapeshellarg($dsn['password']),
                escapeshellarg($dsn['database'])
            ));
        }
        else if ($dsn['phptype'] == 'sqlite') {
            // delete database file -- will be re-initialized on first access
            system(sprintf('rm -f %s', escapeshellarg($dsn['database'])));
        }
    }

    /**
     * Wipe the configured IMAP account and fill with test data
     */
    public static function init_imap()
    {
        if (!TESTS_USER) {
            return false;
        }
        else if (self::$imap_ready !== null) {
            return self::$imap_ready;
        }

        self::connect_imap(TESTS_USER, TESTS_PASS);
        self::purge_mailbox('INBOX');
        self::ensure_mailbox('Archive', true);

        return self::$imap_ready;
    }

    /**
     * Authenticate to IMAP with the given credentials
     */
    public static function connect_imap($username, $password, $host = null)
    {
        $rcmail = rcmail::get_instance();
        $imap = $rcmail->get_storage();

        if ($imap->is_connected()) {
            $imap->close();
            self::$imap_ready = false;
        }

        $imap_host = $host ?: $rcmail->config->get('default_host');
        $a_host = parse_url($imap_host);
        if ($a_host['host']) {
            $imap_host = $a_host['host'];
            $imap_ssl  = isset($a_host['scheme']) && in_array($a_host['scheme'], array('ssl','imaps','tls'));
            $imap_port = isset($a_host['port']) ? $a_host['port'] : ($imap_ssl ? 993 : 143);
        }
        else {
            $imap_port = 143;
            $imap_ssl = false;
        }

        if (!$imap->connect($imap_host, $username, $password, $imap_port, $imap_ssl)) {
            die("IMAP error: unable to authenticate with user " . TESTS_USER);
        }

        self::$imap_ready = true;
    }

    /**
     * Import the given file into IMAP
     */
    public static function import_message($filename, $mailbox = 'INBOX')
    {
        if (!self::init_imap()) {
            die(__METHOD__ . ': IMAP connection unavailable');
        }

        $imap = rcmail::get_instance()->get_storage();
        $imap->save_message($mailbox, file_get_contents($filename));
    }

    /**
     * Delete all messages from the given mailbox
     */
    public static function purge_mailbox($mailbox)
    {
        if (!self::init_imap()) {
            die(__METHOD__ . ': IMAP connection unavailable');
        }

        $imap = rcmail::get_instance()->get_storage();
        $imap->delete_message('*', $mailbox);
    }

    /**
     * Make sure the given mailbox exists in IMAP
     */
    public static function ensure_mailbox($mailbox, $empty = false)
    {
        if (!self::init_imap()) {
            die(__METHOD__ . ': IMAP connection unavailable');
        }

        $imap = rcmail::get_instance()->get_storage();

        $folders = $imap->list_folders();
        if (!in_array($mailbox, $folders)) {
            $imap->create_folder($mailbox, true);
        }
        else if ($empty) {
            $imap->delete_message('*', $mailbox);
        }
    }
}