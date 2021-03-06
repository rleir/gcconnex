<?php
/**
 * Avatar crop action
 *
 */

$guid = get_input('guid');
$owner = get_entity($guid);

if (!$owner || !($owner instanceof ElggUser) || !$owner->canEdit()) {
	register_error(elgg_echo('avatar:crop:fail'));
	forward(REFERER);
}

$x1 = (int) get_input('x1', 0);
$y1 = (int) get_input('y1', 0);
$x2 = (int) get_input('x2', 0);
$y2 = (int) get_input('y2', 0);

$filehandler = new ElggFile();
$filehandler->owner_guid = $owner->getGUID();
$filehandler->setFilename("profile/" . $owner->guid . "master" . ".jpg");
$filename = $filehandler->getFilenameOnFilestore();

// ensuring the avatar image exists in the first place
if (!file_exists($filename)) {
	register_error(elgg_echo('avatar:crop:fail'));
	forward(REFERER);
}

//EW - new ambassaador badge doesnt combine images so we removed this functionality but kept the code
require_once( elgg_get_plugins_path() . "gcProfilePictureBadges/badge_map.php" );	// get array of groups with badges
global $badgemap;
global $initbadges;

//Ambassador badge
$badge_d = get_input( 'badge', '' );
$badge = $badgemap[$badge_d]; 		// which badge

$user = elgg_get_logged_in_user_entity();
$user->active_badge = $badge_d;

//initiative badge
$badge_d = get_input( 'init_badge', '' );
$badge = $initbadges[$badge_d]; 		// which badge

$user->init_badge = $badge;

/////////

$icon_sizes = elgg_get_config('icon_sizes');
unset($icon_sizes['master']);

// get the images and save their file handlers into an array
// so we can do clean up if one fails.
$files = array();
foreach ($icon_sizes as $name => $size_info) {
	$resized = get_resized_image_from_existing_file($filename, $size_info['w'], $size_info['h'], $size_info['square'], $x1, $y1, $x2, $y2, $size_info['upscale']);

	if ($resized) {
		//@todo Make these actual entities.  See exts #348.
		$file = new ElggFile();
		$file->owner_guid = $guid;
		$file->setFilename("profile/{$guid}{$name}.jpg");
		$file->open('write');
		$file->write($resized);
		$file->close();
		$files[] = $file;
	} else {
		// cleanup on fail
		foreach ($files as $file) {
			$file->delete();
		}

		register_error(elgg_echo('avatar:resize:fail'));
		forward(REFERER);
	}
}

$owner->icontime = time();

$owner->x1 = $x1;
$owner->x2 = $x2;
$owner->y1 = $y1;
$owner->y2 = $y2;

system_message(elgg_echo('avatar:crop:success'));
$view = 'river/user/default/profileiconupdate';
elgg_delete_river(array('subject_guid' => $owner->guid, 'view' => $view));
add_to_river($view, 'update', $owner->guid, $owner->guid);

forward(REFERER);
