<?php
/**
 * Ping Users in Threads for MyBB
 * 
 * This plugin allows to ping users inside the threads
 * Pinged users recieve the private message informing who ping them and in which thread
 *
 * @package PingingUsers
 * @author ArButz
 * @version 1.0
 * @license GPL-3.0
 * @copyright Copyright 2026
 *
 * Compatible with MyBB 1.8.x
 * Requires PHP 7.4+
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

/**
 * Plugin information function
 */
function pingingusers_info() {
    return array(
        "name"          => "Pinging Users in threads",
        "description"   => "Allow ping users inside threads and send them private message about the pings.",
        "website"       => "",
        "author"        => "ArButz",
        "authorsite"    => "https://github.com/Buutzz",
        "version"       => "1.0",
        "guid"          => "",
        "codename"      => "pinging_users",
        "compatibility" => "18*"
    );
}

/**
 * Adding hooks
 */
$plugins->add_hook('datahandler_post_insert_post_end', 'pinging_users_datahandler_post_insert_post');
$plugins->add_hook('datahandler_post_insert_thread_end', 'pinging_users_datahandler_post_insert_thread_post');
$plugins->add_hook('datahandler_post_update_end', 'pinging_users_datahandler_post_update');

/**
 * Installing plugin - adding settings to database
 */
function pingingusers_install() {
    global $db;

	$pinging_users_setting_group = [
		"name" => "pinging_users",
		"title" => 'Pings users in threads and inform them about the pings at private message',
		"disporder" => 1,
		"isdefault" => 0,
		"description" => ""
    ];

	$gid = $db->insert_query("settinggroups", $pinging_users_setting_group);

    $gid = intval($gid);

    $pinging_users_settings = [
        [
            'name' => 'pinging_users_on',
            'title' => 'On/Off',
            'description' => 'Turn Pinging Users On or Off',
            'optionscode' => 'yesno',
            'value' => 1,
            'disporder' => 1,
            "gid" => $gid
        ],
        [
            'name' => 'pinging_users_subject',
            'title' => 'Ping informing PM Subject',
            'description' => 'The subject line for the PM sent to the pinged user.',
            'optionscode' => 'text',
            'value' => 'I pinnged you!',
            'disporder' => 2,
            'gid' => $gid,
        ],
        [
            'name' => 'pinging_users_body',
            'title' => 'Ping informing PM Body',
            'description' => 'The message body for the PM sent to the tagged user. To specify the thread they were tagged in, use {thread}.',
            'optionscode' => 'textarea',
            'value' => 'I pinged you here: {thread}',
            'disporder' => 3,
            'gid' => $gid,
        ],
    ];

    foreach ($pinging_users_settings as $new_setting) {
        $db->insert_query('settings', $new_setting);
    }
	
	rebuild_settings();
}

/**
 * Check if plugin is installed
 *
 * @return bool Always returns true (no installation needed)
 */
function pingingusers_is_installed() {
    global $mybb;

    if(isset($mybb->settings['pinging_users_on']))
    {
        return true;
    }

    return false;
}

/**
 * Uninstall plugin
 * 
 * @return void
 */
function pingingusers_uninstall() {
	global $db;
	$query = $db->simple_select("settinggroups", "gid", "name='pinging_users'");
	$gid = $db->fetch_field($query, "gid");
	if(!$gid) {
		return;
	}
	$db->delete_query("settinggroups", "name='pinging_users'");
	$db->delete_query("settings", "gid=$gid");
	rebuild_settings();
}

function pingingusers_activate() {}

function pingingusers_deactivate() {}

/**
 * Sending DM to the users informing of the ping in the thread
 */
function ping_user_send_dm($pingData) {
    if (empty($pingData['tid'])
        || empty($pingData['pid'])
        || empty($pingData['userId'])
    ) {
        return; 
    }

    require_once MYBB_ROOT."inc/datahandlers/pm.php";
    global $mybb;

    $dmHandler = new PMDataHandler();
    $dmHandler->admin_override = true;

    $dmSubject = $mybb->settings['pinging_users_subject'];
    $dmMsg = $mybb->settings['pinging_users_body'];
    $dmMsg = str_replace('{thread}', "[url=" . $mybb->settings["bburl"] . "/showthread.php?tid=" . $pingData['tid'] . "&pid=" . $pingData['pid'] . "#pid" . $pingData['pid'] . "]" . $mybb->settings["bburl"] . "/showthread.php?tid=" . $pingData['tid'] . "&pid=" . $pingData['pid'] . "#" . $pingData['pid'] . "[/url]", $dmMsg);

    $dmData = [
        'subject' => $dmSubject,
        'message' => $dmMsg,
        "fromid" => $mybb->user['uid'],
        'toid' => $pingData['userId'],
        "options" => [
            "savecopy" => "0"
        ],
    ];
    
    $dmHandler->set_data($dmData);
    if ($dmHandler->validate_pm()) {
        $dmHandler->insert_pm();
    }
}

function ping_users_handler($postData) {
    global $db, $mybb;

    $pingedUsers = [];
    $maxlen = (int)$mybb->settings['maxnamelength'];

    $msg = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $postData['msg']);

    $msg = preg_replace_callback(
        '/\[url=.*?\].*?\[\/url\](*SKIP)(*F)|@([A-Za-z0-9\' ]{1,' . $maxlen . '})/u',
        function($match) use ($db, $mybb, &$pingedUsers, $postData) {

            if (!isset($match[1])) return $match[0];

            $username = trim($match[1]);
            if ($username === '') return $match[0];

            $usernameEscaped = $db->escape_string($username);

            $query = $db->simple_select(
                "users",
                "uid, username",
                "LOWER(username) = '" . strtolower($usernameEscaped) . "'"
            );

            $user = $db->fetch_array($query);

            if (!empty($user) && !in_array($user['uid'], $pingedUsers)) {
                $pingedUsers[] = $user['uid'];

                ping_user_send_dm([
                    'tid' => $postData['tid'],
                    'pid' => $postData['pid'],
                    'userId' => $user['uid'],
                ]);

                return '[url='.$mybb->settings['bburl'].'/member.php?action=profile&uid='.$user['uid'].']@'.$user['username'].'[/url]';
            }

            return $match[0];
        },
        $msg
    );

    return $msg;
}

function pinging_users_datahandler_post_insert_post(&$post) {
    global $mybb, $db;

    if ($post->return_values['visible'] != 1) {
        return;
    }
    if (!$mybb->settings['pinging_users_on']) {
        return;
    }

    $data = count($post->post_insert_data) > 0 ? $post->post_insert_data : $post->post_update_data;

    $postData = [
        'msg' => $data['message'],
        'tid' => $post->data['tid'],
        'pid' => $post->return_values['pid'],
    ];

    $newMsg = ping_users_handler($postData);

    if ($newMsg !== $postData['msg']) {
        $db->update_query('posts', [ 'message' => $newMsg ], "pid='{$postData['pid']}'");
    }
    return $post;
}

function pinging_users_datahandler_post_update(&$post) {
    global $mybb, $db;

    if (!$mybb->settings['pinging_users_on']) {
        return;
    }

    $postData = [
        'msg' => $post->post_update_data['message'],
        'tid' => $post->data['tid'],
        'pid' => $post->data['pid'],
    ];
    $newMsg = ping_users_handler($postData);
    if ($newMsg !== $postData['msg']) {
        $db->update_query('posts', [ 'message' => $newMsg ], "pid='{$postData['pid']}'");
    }
    return $post;
}

function pinging_users_datahandler_post_insert_thread_post(&$post) {
    global $mybb, $db;

    if ($post->return_values['visible'] != 1) {
        return;
    }
    if (!$mybb->settings['pinging_users_on']) {
        return;
    }

    $data = count($post->post_insert_data) > 0 ? $post->post_insert_data : $post->post_update_data;

    $postData = [
        'msg' => $data['message'],
        'tid' => $post->return_values['tid'],
        'pid' => $post->return_values['pid'],
    ];

    $newMsg = ping_users_handler($postData);

    if ($newMsg !== $postData['msg']) {
        $db->update_query('posts', [ 'message' => $newMsg ], "pid='{$postData['pid']}'");
    }
    return $post;
}