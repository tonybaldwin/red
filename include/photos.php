<?php /** @file */

require_once('include/permissions.php');
require_once('include/items.php');
require_once('include/photo/photo_driver.php');


function photo_upload($channel, $observer, $args) {

	$ret = array('success' => false);
	$channel_id = $channel['channel_id'];
	$account_id = $channel['channel_account_id'];

	if(! perm_is_allowed($channel_id, $observer['xchan_hash'], 'post_photos')) {
		$ret['message'] = t('Permission denied.');
		return $ret;
	}

	call_hooks('photo_upload_begin', $args);

	/**
	 * Determine the album to use
	 */

	$album       = $args['album'];
	$newalbum    = $args['newalbum'];

	logger('photo_upload: album= ' . $album . ' newalbum= ' . $newalbum , LOGGER_DEBUG);

	if(! $album) {
		if($newalbum)
			$album = $newalbum;
		else
			$album = datetime_convert('UTC',date_default_timezone_get(),'now', 'Y');
	}

	/**
	 *
	 * We create a wall item for every photo, but we don't want to
	 * overwhelm the data stream with a hundred newly uploaded photos.
	 * So we will make the first photo uploaded to this album in the last several hours
	 * visible by default, the rest will become visible over time when and if
	 * they acquire comments, likes, dislikes, and/or tags 
	 *
	 */

	$r = q("SELECT * FROM photo WHERE album = '%s' AND uid = %d AND created > UTC_TIMESTAMP() - INTERVAL 3 HOUR ",
		dbesc($album),
		intval($channel_id)
	);
	if((! $r) || ($album == t('Profile Photos')))
		$visible = 1;
	else
		$visible = 0;
	
	if(intval($args['not_visible']) || $args['not_visible'] === 'true')
		$visible = 0;

	$str_group_allow   = perms2str(((is_array($args['group_allow']))   ? $args['group_allow']   : explode(',',$args['group_allow'])));
	$str_contact_allow = perms2str(((is_array($args['contact_allow'])) ? $args['contact_allow'] : explode(',',$args['contact_allow'])));
	$str_group_deny    = perms2str(((is_array($args['group_deny']))    ? $args['group_deny']    : explode(',',$args['group_deny'])));
	$str_contact_deny  = perms2str(((is_array($args['contact_deny']))  ? $args['contact_deny']  : explode(',',$args['contact_deny'])));

	$f = array('src' => '', 'filename' => '', 'filesize' => 0, 'type' => '');

	call_hooks('photo_upload_file',$f);

	if(x($f,'src') && x($f,'filesize')) {
		$src      = $f['src'];
		$filename = $f['filename'];
		$filesize = $f['filesize'];
		$type     = $f['type'];
	}
	else {
		$src        = $_FILES['userfile']['tmp_name'];
		$filename   = basename($_FILES['userfile']['name']);
		$filesize   = intval($_FILES['userfile']['size']);
		$type       = $_FILES['userfile']['type'];
	}

	if (! $type) 
		$type=guess_image_type($filename);

	logger('photo_upload: received file: ' . $filename . ' as ' . $src . ' ('. $type . ') ' . $filesize . ' bytes', LOGGER_DEBUG);

	$maximagesize = get_config('system','maximagesize');

	if(($maximagesize) && ($filesize > $maximagesize)) {
		$ret['message'] =  sprintf ( t('Image exceeds website size limit of %lu bytes'), $maximagesize);
		@unlink($src);
		call_hooks('photo_upload_end',$ret);
		return $ret;
	}

	if(! $filesize) {
		$ret['message'] = t('Image file is empty.');
		@unlink($src);
		call_hooks('photo_post_end',$ret);
		return $ret;
	}

	logger('photo_upload: loading the contents of ' . $src , LOGGER_DEBUG);

	$imagedata = @file_get_contents($src);

	$r = q("select sum(size) as total from photo where aid = %d and scale = 0 ",
		intval($account_id)
	);

	$limit = service_class_fetch($channel_id,'photo_upload_limit');

	if(($r) && ($limit !== false) && (($r[0]['total'] + strlen($imagedata)) > $limit)) {
		$ret['message'] = upgrade_message();
		@unlink($src);
		call_hooks('photo_post_end',$ret);
		return $ret;
	}
		

	$ph = photo_factory($imagedata, $type);

	if(! $ph->is_valid()) {
		$ret['message'] = t('Unable to process image');
		logger('photo_upload: unable to process image');
		@unlink($src);
		call_hooks('photo_upload_end',$ret);
		return $ret;
	}

	$ph->orient($src);
	@unlink($src);

	$max_length = get_config('system','max_image_length');
	if(! $max_length)
		$max_length = MAX_IMAGE_LENGTH;
	if($max_length > 0)
		$ph->scaleImage($max_length);

	$width  = $ph->getWidth();
	$height = $ph->getHeight();

	$smallest = 0;

	$photo_hash = photo_new_resource();

	$visitor = '';
	if($channel['channel_hash'] !== $observer['xchan_hash'])
		$visitor = $observer['xchan_hash'];

	$errors = false;

	$p = array('aid' => $account_id, 'uid' => $channel_id, 'xchan' => $visitor, 'resource_id' => $photo_hash,
		'filename' => $filename, 'album' => $album, 'scale' => 0, 'photo_flags' => PHOTO_NORMAL, 
		'allow_cid' => $str_contact_allow, 'allow_gid' => $str_group_allow,
		'deny_cid' => $str_contact_deny, 'deny_gid' => $str_group_deny
	);

	$r1 = $ph->save($p);
	if(! $r1)
		$errors = true;
		
	if(($width > 640 || $height > 640) && (! $errors)) {
		$ph->scaleImage(640);
		$p['scale'] = 1;
		$r2 = $ph->save($p);
		$smallest = 1;
		if(! $r2)
			$errors = true;
	}

	if(($width > 320 || $height > 320) && (! $errors)) {
		$ph->scaleImage(320);
		$p['scale'] = 2;
		$r3 = $ph->save($p);
		$smallest = 2;
		if(! $r3)
			$errors = true;
	}

	
	if($errors) {
		q("delete from photo where resource_id = '%s' and uid = %d",
			dbesc($photo_hash),
			intval($channel_id)
		);
		$ret['message'] = t('Photo storage failed.');
		logger('photo_upload: photo store failed.');
		call_hooks('photo_upload_end',$ret);
		return $ret;
	}

	// This will be the width and height of the smallest representation

	$width_x_height = $ph->getWidth() . 'x' . $ph->getHeight();

	$basename = basename($filename);
	$mid = item_message_id();

	// Create item container

	$item_flags = ITEM_WALL|ITEM_ORIGIN|ITEM_THREAD_TOP;
	$item_restrict = (($visible) ? ITEM_VISIBLE : ITEM_HIDDEN);			
	$title = '';
	$mid = item_message_id();
			
	$arr = array();

	$arr['aid']           = $account_id;
	$arr['uid']           = $channel_id;
	$arr['mid']           = $mid;
	$arr['parent_mid']    = $mid; 
	$arr['item_flags']    = $item_flags;
	$arr['item_restrict'] = $item_restrict;
	$arr['resource_type'] = 'photo';
	$arr['resource_id']   = $photo_hash;
	$arr['owner_xchan']   = $channel['channel_hash'];
	$arr['author_xchan']  = $observer['xchan_hash'];
	$arr['title']         = $title;
	$arr['allow_cid']     = $str_contact_allow;
	$arr['allow_gid']     = $str_group_allow;
	$arr['deny_cid']      = $str_contact_deny;
	$arr['deny_gid']      = $str_group_deny;
	$arr['verb']          = ACTIVITY_POST;

	$arr['plink']         = z_root() . '/channel/' . $channel['channel_address'] . '/?f=&mid=' . $arr['mid'];

	if ($width_x_height)
		$tag = '[zmg=' . $width_x_height. ']';
	else
		$tag = '[zmg]';

	$arr['body']          = '[zrl=' . z_root() . '/photos/' . $channel['channel_address'] . '/image/' . $photo_hash . ']' 
				. $tag . z_root() . "/photo/{$photo_hash}-{$smallest}.".$ph->getExt() . '[/zmg]'
				. '[/zrl]';
		
	$result = item_store($arr);
	$item_id = $result['item_id'];

	if($visible) 
		proc_run('php', "include/notifier.php", 'wall-new', $item_id);

	$ret['success'] = true;
	$ret['body'] = $arr['body'];
	$ret['resource_id'] = $photo_hash;
	$ret['photoitem_id'] = $item_id;

	call_hooks('photo_upload_end',$ret);

	return $ret;
}




function photos_albums_list($channel,$observer) {

	$channel_id     = $channel['channel_id'];
	$observer_xchan = (($observer) ? $observer['xchan_hash'] : '');

	if(! perm_is_allowed($channel_id,$observer_xchan,'view_photos'))
		return false;

	// FIXME - create a permissions SQL which works on arbitrary observers and channels, regardless of login or web status

	$sql_extra = permissions_sql($channel_id);

	$albums = q("SELECT count( distinct resource_id ) as total, album from photo where uid = %d and ( photo_flags = %d or photo_flags = %d ) $sql_extra group by album order by created desc",
		intval($channel_id),
		intval(PHOTO_NORMAL),
		intval(PHOTO_PROFILE)

	);

	// add various encodings to the array so we can just loop through and pick them out in a template

	$ret = array('success' => false);

	if($albums) {
		$ret['success'] = true;
		$ret['albums'] = array();
		foreach($albums as $k => $album) {
			$entry = array(
				'text' => $album['album'],
				'total' => $album['total'], 
				'url' => z_root() . '/photos/' . $channel['channel_address'] . '/album/' . bin2hex($album['album']), 
				'urlencode' => urlencode($album['album']),
				'bin2hex' => bin2hex($album['album']));
			$ret['albums'][] = $entry;
		}
	}
	return $ret;

}

function photos_album_widget($channelx,$observer,$albums = null) {

	$o = '';

	// If we weren't passed an album list, see if the photos module
	// dropped one for us to find in $a->data['albums']. 
	// If all else fails, load it.

	if(! $albums) {
		if(array_key_exists('albums', get_app()->data))
			$albums = get_app()->data['albums'];
		else
			$albums = photos_albums_list($channelx,$observer);
	}

	if($albums['success']) {
		$o = replace_macros(get_markup_template('photo_albums.tpl'),array(
			'$nick'    => $channelx['channel_address'],
			'$title'   => t('Photo Albums'),
			'$albums'  => $albums['albums'],
			'$baseurl' => z_root(),
			'$upload'  => ((perm_is_allowed($channelx['channel_id'],(($observer) ? $observer['xchan_hash'] : ''),'post_photos')) 
				? t('Upload New Photos') : '')
		));
	}
	return $o;
}


function photos_list_photos($channel,$observer,$album = '') {

	$channel_id     = $channel['channel_id'];
	$observer_xchan = (($observer) ? $observer['xchan_hash'] : '');

	if(! perm_is_allowed($channel_id,$observer_xchan,'view_photos'))
		return false;

	$sql_extra = permissions_sql($channel_id);

	if($album)
		$sql_extra .= " and album = '" . protect_sprintf(dbesc($album)) . "' "; 

	$ret = array('success' => false);

	$r = q("select resource_id, created, edited, title, description, album, filename, type, height, width, size, scale, profile, photo_flags, allow_cid, allow_gid, deny_cid, deny_gid from photo where uid = %d and ( photo_flags = %d or photo_flags = %d ) $sql_extra ",
		intval($channel_id),
		intval(PHOTO_NORMAL),
		intval(PHOTO_PROFILE)
	);
	
	if($r) {
		for($x = 0; $x < count($r); $x ++) {
			$r[$x]['src'] = z_root() . '/photo/' . $r[$x]['resource_id'] . '-' . $r[$x]['scale'];
		}
		$ret['success'] = true;
		$ret['photos'] = $r;
	}

	return $ret;
}



function photos_album_exists($channel_id,$album) {
	$r = q("SELECT id from photo where album = '%s' and uid = %d limit 1",
		dbesc($album),
		intval($channel_id)
	);
	return (($r) ? true : false);
}

function photos_album_rename($channel_id,$oldname,$newname) {
	return q("UPDATE photo SET album = '%s' WHERE album = '%s' AND uid = %d",
		dbesc($newname),
		dbesc($oldname),
		intval($channel_id)
	);
}


function photos_album_get_db_idstr($channel_id,$album,$remote_xchan = '') {

	if($remote_xchan) {
		$r = q("SELECT distinct resource_id as from photo where xchan = '%s' and uid = %d and album = '%s' ",
			dbesc($remote_xchan),
			intval($channel_id),
			dbesc($album)
		);
	}
	else {
		$r = q("SELECT distinct resource_id  from photo where uid = %d and album = '%s' ",
			intval($channel_id),
			dbesc($album)
		);
	}
	if($r) {
		$arr = array();
		foreach($r as $rr) {
			$arr[] = "'" . dbesc($rr['resource_id']) . "'" ;
		}
		$str = implode(',',$arr);
		return $str;
	}
	return false;

}

function photos_create_item($channel, $creator_hash, $photo, $visible = false) {

	// Create item container

	$item_flags = ITEM_WALL|ITEM_ORIGIN|ITEM_THREAD_TOP;
	$item_restrict = (($visible) ? ITEM_HIDDEN : ITEM_VISIBLE);			

	$title = '';
	$mid = item_message_id();
			
	$arr = array();

	$arr['aid']           = $channel['channel_account_id'];
	$arr['uid']           = $channel['channel_id'];
	$arr['mid']           = $mid;
	$arr['parent_mid']    = $mid; 
	$arr['item_flags']    = $item_flags;
	$arr['item_restrict'] = $item_restrict;
	$arr['resource_type'] = 'photo';
	$arr['resource_id']   = $photo['resource_id'];
	$arr['owner_xchan']   = $channel['channel_hash'];
	$arr['author_xchan']  = $creator_hash;

	$arr['allow_cid']     = $photo['allow_cid'];
	$arr['allow_gid']     = $photo['allow_gid'];
	$arr['deny_cid']      = $photo['deny_cid'];
	$arr['deny_gid']      = $photo['deny_gid'];

	$arr['plink']         = z_root() . '/channel/' . $channel['channel_address'] . '/?f=&mid=' . $arr['mid'];
			
	$arr['body']          = '[zrl=' . z_root() . '/photos/' . $channel['channel_address'] . '/image/' . $photo['resource_id'] . ']' 
		. '[zmg]' . z_root() . '/photo/' . $photo['resource_id'] . '-' . $photo['scale'] . '[/zmg]' 
		. '[/zrl]';
		
	$result = item_store($arr);
	$item_id = $result['item_id'];
	return $item_id;

}
