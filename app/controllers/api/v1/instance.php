<?php
	error_reporting(E_ALL & ~E_NOTICE);
	db_connect();
	$json_a = array();

	if($mode == 'activity'){
		date_default_timezone_set('Asia/Tokyo');
		$redis = new Redis();
		$redis->connect("XXXXXXXXX", XXXXX);
		$redisdb = getRedisDB(XXXXXXXXXXXX);
		if ($redisdb < 0) {
			http_response_code(202);
			exit;
		}
		$redis->select($redisdb);
		$wno = gmdate("N",time());//1（月曜日）から 7（日曜日）
		for($weekcnt=0;$weekcnt < 12;$weekcnt++){
			$week_a = array();
			//今日が日曜 $wno=0 月曜になる為引くべき日数 1
			$subtractdays = $weekcnt*7 + $wno - 1;//月曜日を週始まり
			$wtime = strtotime("-{$subtractdays} day", time());
			$wdate = gmdate('Y-m-d', $wtime);//週初めの日
			$weekid = intval(gmdate('W', $wtime));//暦週
			$week_a['week'] = strtotime($wdate);
	        //statuses: Redis.current.get("activity:statuses:local:#{week_id}") || '0',
	        if(!($week_a['statuses'] = $redis->get("activity:statuses:local:{$weekid}"))){
	        	$week_a['statuses'] = "0";
	        }
	        //logins: Redis.current.pfcount("activity:logins:#{week_id}").to_s,
			$week_a['logins'] = strval($redis->pfcount("activity:logins:{$weekid}"));
	        //registrations: Redis.current.get("activity:accounts:local:#{week_id}") || '0',
			if(!($week_a['registrations'] = $redis->get("activity:accounts:local:{$weekid}"))){
	        	$week_a['registrations'] = "0";
			}
			$json_a[] = $week_a;
		}
		RedisClose($redis);
	}else if($mode == 'peers'){
		$json_a = domain_list();
	}else if($mode == 'instance'){
		$env_a = env_get("/XXXXXXXXXXX/live/.env.production");
		//.env.productionの LOCAL_DOMAIN
		$json_a['uri'] = $env_a["LOCAL_DOMAIN"];
		//settingsテーブルの var=site_title
		$json_a['title'] = setting_get("site_title");
		//settingsテーブルの var=site_description
		$json_a['description'] = setting_get("site_description");
		//settingsテーブルの var=site_contact_email
		$json_a['email'] = setting_get("site_contact_email");
		//lib/mastodon/version.rbのソース内変数
		$ver_a = rbdef_get("/XXXXXXXXXXXX/live/lib/mastodon/version.rb");
		if($ver_a['pre']){
			$json_a['version'] = "{$ver_a['major']}.{$ver_a['minor']}.{$ver_a['patch']}.{$ver_a['pre']}";
		}else{
			$json_a['version'] = "{$ver_a['major']}.{$ver_a['minor']}.{$ver_a['patch']}";
		}
		$json_a['urls'] = array("streaming_api" => "wss://{$json_a['uri']}");
		$json_a['stats'] = array("user_count" => user_count(),"status_count" => status_count(),"domain_count" => domain_count());
		// https://DOMAIN/packs/preview-*.jpg ←これを探す
		$json_a['thumbnail'] = "https://{$json_a['uri']}/packs/".basename(filename_find("/XXXXXXXXXX/live/public/packs","preview.jpg"));
	}
	header('content-type: application/json; charset=utf-8');
	echo str_replace("\\\\","\\",json_encode($json_a, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_UNESCAPED_SLASHES|JSON_HEX_AMP));
	exit;

	function filename_find($serch_dir,$filename){
		//キャッシュファイル名は，元の拡張子無しファイル名＋-ハッシュ文字列＋拡張子
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$base = basename($filename , "." . $ext);
		$serch_exp = "/$base\-\w*\.$ext\$/";
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($serch_dir, FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::SKIP_DOTS));
		$files = new RegexIterator($files, $serch_exp, RecursiveRegexIterator::MATCH);
		foreach($files as $file_path => $file_info) {
			if ( ! $file_info->isFile()) {	//ファイルのみ抽出
				continue;
			}
			return $file_path;
		}
		return "";
	}

	function user_count(){
		global $db;
		$sql = "select count(*) from users where confirmed_at is not null";
		$result = pg_query($db, $sql);
		$cnt = pg_num_rows($result);
		if($cnt > 0){
			$record_a = pg_fetch_assoc($result,0);
			return intval($record_a['count']);
		}
		return 0;
	}
	function status_count(){
		global $db;
		$sql = "select count(*) from statuses,accounts where statuses.account_id = accounts.id and accounts.domain is null";
		$result = pg_query($db, $sql);
		$cnt = pg_num_rows($result);
		if($cnt > 0){
			$record_a = pg_fetch_assoc($result,0);
			return intval($record_a['count']);
		}
		return 0;
	}
	function domain_count(){
		global $db;
		$sql = "select distinct on (domain) domain from accounts where domain is not null";
		$result = pg_query($db, $sql);
		return pg_num_rows($result);
	}
	
	function domain_list(){
		global $db;
		$sql = "select distinct on (domain) domain from accounts where domain is not null";
		$result = pg_query($db, $sql);
		$cnt = pg_num_rows($result);
		$ret_a = array();
		if($cnt > 0){
			for($i=0;$i<$cnt;$i++){
				$record_a = pg_fetch_assoc($result,$i);
				$ret_a[] = $record_a['domain'];
			}
		}
		return $ret_a;
	}

	function rbdef_get($filename){
		$record_a = explode("\n",file_get_contents($filename));
		$ret_a = array();
		$nextisvalue = false;
		foreach ($record_a as $record){
			$record = trim($record);
			if($nextisvalue){
				if($record != 'nil'){
					$ret_a[$fieldid] = $record;
				}
				$nextisvalue = false;
				continue;
			}
			if(substr($record,0,3) == 'def'){
				list($def,$fieldid) = explode(' ',$record);
				$nextisvalue = true;
			}
		}
		return $ret_a;
	}

	function setting_get($var){
		global $db;
		$sql = "select value from settings where var = '{$var}'";
		$result = pg_query($db, $sql);
		$cnt = pg_num_rows($result);
		if($cnt > 0){
			$settings = pg_fetch_assoc($result,0);
			$value = trim(str_replace(chr(10),"",$settings["value"])," -'.\"\t\n\r\0\x0B");
			return $value;
		}
		return "";
	}

	function env_get($filename){
		$record_a = explode("\n",file_get_contents($filename));
		$ret_a = array();
		foreach ($record_a as $record){
			list($fieldid,$value) = explode('=',trim($record));
			$ret_a[$fieldid] = $value;
		}
		return $ret_a;
	}
	function db_connect(){
		global $db,XXXXXXXXXXXX;
		if(preg_match('/XXXXXXXXX/live\/public$/',XXXXXXXXXXXXXX,$match_a)){
			if(XXXXXXXXXXXXXXX){
				$db = pg_connect("host=XXXXXXXX dbname=XXXXXXXX user=XXXXXXXXXX password=XXXXXXXXXX");
				if (!$db) {
					http_response_code(202);
					exit;
				}
			}else{
				http_response_code(202);
				exit;
			}
		}
		return;
	}
	function getRedisDB($username) {
		$ret = -1;
		$data = file("/XXXXXXXX/live/.env.production");
		foreach ($data as $key => $var) {
			if (strpos($var, "REDIS_DB") === 0) {
				$work = preg_match("/[0-9].*/", $var, $matches);
				$ret = $matches[0];
				break;
			}
		}
		return $ret;
	}
	function RedisClose(Redis $redis) {
		if ($redis != null) {
			$redis->close();
		}
		$redis = null;
	}

?>
