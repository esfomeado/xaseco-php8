<?php
/**
 * Fake TrackMania Forever dedicated server for testing XAseco.
 *
 * Speaks GbxRemote 2 (XML-RPC over TCP), answers the methods XAseco uses with
 * structures as documented in DOCS/ListMethodsForever.html, and plays a
 * scenario of callbacks (players connecting, chatting, driving, races ending).
 *
 * Usage: php fake_tmserver.php <port> <tracks_dir> <scenario.php> [log]
 */
error_reporting(E_ALL);
define('SERVER_LOGIN', getenv('SERVER_LOGIN') ?: 'testserver');

[$_, $port, $tracksDir, $scenarioFile] = $argv;
$logFile = $argv[4] ?? 'php://stderr';
$tracksDir = rtrim($tracksDir, '/') . '/';

function logmsg($s) { global $logFile; file_put_contents($logFile, date('H:i:s') . " $s\n", FILE_APPEND); }

// ---------------------------------------------------------------- XML-RPC

function xr_encode($v) {
	if (is_bool($v)) return '<boolean>' . ($v ? 1 : 0) . '</boolean>';
	if (is_int($v)) return "<i4>$v</i4>";
	if (is_float($v)) return "<double>$v</double>";
	if (is_string($v)) return '<string>' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string>';
	if (is_array($v)) {
		if (array_is_list($v)) {
			$s = '<array><data>';
			foreach ($v as $e) $s .= '<value>' . xr_encode($e) . '</value>';
			return $s . '</data></array>';
		}
		$s = '<struct>';
		foreach ($v as $k => $e) $s .= '<member><name>' . htmlspecialchars((string)$k, ENT_XML1) . '</name><value>' . xr_encode($e) . '</value></member>';
		return $s . '</struct>';
	}
	return '<string></string>';
}

function xr_decode_value(SimpleXMLElement $value) {
	$children = $value->children();
	if (count($children) === 0) return (string)$value;
	$c = $children[0];
	switch ($c->getName()) {
		case 'i4': case 'int': return (int)$c;
		case 'double': return (float)$c;
		case 'boolean': return ((string)$c) === '1';
		case 'string': return (string)$c;
		case 'base64': return base64_decode((string)$c);
		case 'array':
			$out = [];
			foreach ($c->data->value as $v) $out[] = xr_decode_value($v);
			return $out;
		case 'struct':
			$out = [];
			foreach ($c->member as $m) $out[(string)$m->name] = xr_decode_value($m->value);
			return $out;
	}
	return (string)$c;
}

function xr_parse_call($xml) {
	$x = simplexml_load_string($xml);
	$params = [];
	if (isset($x->params)) foreach ($x->params->param as $p) $params[] = xr_decode_value($p->value);
	return [(string)$x->methodName, $params];
}

function xr_response($v) {
	return '<?xml version="1.0" encoding="UTF-8"?><methodResponse><params><param><value>' . xr_encode($v) . '</value></param></params></methodResponse>';
}

function xr_fault($code, $msg) {
	return '<?xml version="1.0" encoding="UTF-8"?><methodResponse><fault><value>' . xr_encode(['faultCode' => $code, 'faultString' => $msg]) . '</value></fault></methodResponse>';
}

function xr_call($method, $params) {
	$s = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' . $method . '</methodName><params>';
	foreach ($params as $p) $s .= '<param><value>' . xr_encode($p) . '</value></param>';
	return $s . '</params></methodCall>';
}

// ---------------------------------------------------------------- server state

// challenges: read the real track files with XAseco's own GBX reader
$GLOBALS['CHALLENGES'] = [];
function load_challenges($tracksDir, $max) {
	$files = glob($tracksDir . 'Campaigns/Downloaded/*.Challenge.Gbx');
	sort($files);
	$out = [];
	foreach (array_slice($files, 0, $max) as $f) {
		$gbx = new GBXChallMapFetcher(true, false, false);
		try { $gbx->processFile($f); } catch (Exception $e) { continue; }
		$out[] = [
			'Name' => $gbx->name, 'UId' => $gbx->uid,
			'FileName' => substr($f, strlen($tracksDir)), 'Author' => $gbx->author,
			'Environnement' => $gbx->envir, 'Mood' => $gbx->mood,
			'BronzeTime' => (int)$gbx->bronzeTime, 'SilverTime' => (int)$gbx->silverTime,
			'GoldTime' => (int)$gbx->goldTime, 'AuthorTime' => (int)$gbx->authorTime,
			'CopperPrice' => (int)$gbx->cost, 'LapRace' => (bool)$gbx->multiLap,
			'NbLaps' => (int)$gbx->nbLaps, 'NbCheckpoints' => max(4, (int)$gbx->nbChecks),
		];
	}
	return $out;
}

$STATE = [
	'cur' => 0,
	'players' => [],   // login => info
	'nextPlayerId' => 236,
	'status' => [4, 'Running - Play'],
	'gamemode' => 1,   // 1 = TimeAttack
	'lists' => ['Ban' => [], 'Black' => [], 'Guest' => [], 'Ignore' => []],
];

function challenge($i = null) {
	global $STATE;
	$list = $GLOBALS['CHALLENGES'];
	return $list[($i ?? $STATE['cur']) % count($list)];
}

function player_info($p) {
	return ['Login' => $p['Login'], 'NickName' => $p['NickName'], 'PlayerId' => $p['PlayerId'],
	        'TeamId' => -1, 'SpectatorStatus' => $p['IsSpectator'] ? 1 : 0,
	        'LadderRanking' => $p['LadderRanking'], 'Flags' => 1000000 + ($p['IsSpectator'] ? 1 : 0)];
}

function detailed_player_info($p) {
	return ['Login' => $p['Login'], 'NickName' => $p['NickName'], 'PlayerId' => $p['PlayerId'],
	        'TeamId' => -1, 'Path' => $p['Path'], 'Language' => 'en', 'ClientVersion' => '2.11.26',
	        'IPAddress' => '127.0.0.1:2350', 'DownloadRate' => 8000, 'UploadRate' => 8000,
	        'IsSpectator' => $p['IsSpectator'], 'IsInOfficialMode' => false,
	        'Avatar' => ['FileName' => 'Skins/Avatars/Default.dds', 'Checksum' => ''],
	        'Skins' => [], 'HoursSinceZoneInscription' => 100, 'OnlineRights' => 3,
	        'LadderStats' => ['LastMatchScore' => 0.0, 'NbrMatchWins' => 3, 'NbrMatchDraws' => 0,
	                          'NbrMatchLosses' => 2, 'TeamName' => '',
	                          'PlayerRankings' => [['Path' => $p['Path'], 'Score' => 25000.0,
	                                                'Ranking' => $p['LadderRanking'], 'TotalCount' => 100000]]]];
}

function game_info() {
	global $STATE;
	return ['GameMode' => $STATE['gamemode'], 'ChatTime' => 10000, 'NbChallenge' => count($GLOBALS['CHALLENGES']),
	        'RoundsPointsLimit' => 50, 'RoundsUseNewRules' => false, 'RoundsForcedLaps' => 0,
	        'TimeAttackLimit' => 300000, 'TimeAttackSynchStartPeriod' => 0, 'TeamPointsLimit' => 50,
	        'TeamMaxPoints' => 6, 'TeamUseNewRules' => false, 'LapsNbLaps' => 5, 'LapsTimeLimit' => 0,
	        'FinishTimeout' => 1, 'AllWarmUpDuration' => 0, 'DisableRespawn' => false,
	        'ForceShowAllOpponents' => 0, 'RoundsPointsLimitNewRules' => 5, 'TeamPointsLimitNewRules' => 5,
	        'CupPointsLimit' => 100, 'CupRoundsPerChallenge' => 5, 'CupNbWinners' => 3, 'CupWarmUpDuration' => 2];
}

function cur_next($v) { return ['CurrentValue' => $v, 'NextValue' => $v]; }

function handle_method($method, $params) {
	global $STATE, $tracksDir;
	$list = $GLOBALS['CHALLENGES'];
	switch ($method) {
		case 'system.multicall':
			$out = [];
			foreach ($params[0] as $call) {
				$r = handle_method($call['methodName'], $call['params'] ?? []);
				$out[] = ($r instanceof Fault) ? ['faultCode' => $r->code, 'faultString' => $r->msg] : [$r];
			}
			return $out;
		case 'GetVersion': return ['Name' => 'TmForever', 'Version' => '2.11.26', 'Build' => '2011-02-21'];
		case 'GetSystemInfo': return ['PublishedIp' => '127.0.0.1', 'Port' => 2350, 'P2PPort' => 3450,
		                              'ServerLogin' => SERVER_LOGIN, 'ServerPlayerId' => 235,
		                              'ConnectionDownloadRate' => 8000, 'ConnectionUploadRate' => 8000];
		case 'GetServerOptions': return ['Name' => '$s$f80Test Server', 'Comment' => 'harness', 'Password' => '',
		                                 'PasswordForSpectator' => '', 'CurrentMaxPlayers' => 32, 'NextMaxPlayers' => 32,
		                                 'CurrentMaxSpectators' => 32, 'NextMaxSpectators' => 32, 'IsP2PUpload' => false,
		                                 'IsP2PDownload' => false, 'CurrentLadderMode' => 1, 'NextLadderMode' => 1,
		                                 'CurrentVehicleNetQuality' => 0, 'NextVehicleNetQuality' => 0,
		                                 'CurrentCallVoteTimeOut' => 60000, 'NextCallVoteTimeOut' => 60000,
		                                 'CallVoteRatio' => 0.5, 'AllowChallengeDownload' => true, 'AutoSaveReplays' => false,
		                                 'RefereePassword' => '', 'RefereeMode' => 0, 'AutoSaveValidationReplays' => false,
		                                 'HideServer' => 0, 'CurrentUseChangingValidationSeed' => false,
		                                 'NextUseChangingValidationSeed' => false];
		case 'GetCurrentGameInfo': case 'GetNextGameInfo': return game_info();
		case 'GetStatus': return ['Code' => $STATE['status'][0], 'Name' => $STATE['status'][1]];
		case 'GetPlayerList':
			$out = [];
			foreach ($STATE['players'] as $p) $out[] = player_info($p);
			return array_slice($out, $params[1] ?? 0, $params[0] ?? 300);
		case 'GetPlayerInfo':
			if (!isset($STATE['players'][$params[0]])) return new Fault(-1000, 'Login unknown.');
			return player_info($STATE['players'][$params[0]]);
		case 'GetDetailedPlayerInfo':
			if ($params[0] === SERVER_LOGIN)
				return detailed_player_info(['Login' => SERVER_LOGIN, 'NickName' => '$s$f80Test Server', 'PlayerId' => 235, 'Path' => 'World|Portugal|Lisboa', 'IsSpectator' => false, 'LadderRanking' => 0]);
			if (!isset($STATE['players'][$params[0]])) return new Fault(-1000, 'Login unknown.');
			return detailed_player_info($STATE['players'][$params[0]]);
		case 'GetMainServerPlayerInfo':
			return ['Login' => SERVER_LOGIN, 'NickName' => 'Test Server', 'PlayerId' => 235, 'TeamId' => -1,
			        'SpectatorStatus' => 0, 'LadderRanking' => 0, 'Flags' => 100000];
		case 'GetCurrentChallengeInfo': return challenge();
		case 'GetNextChallengeInfo': return challenge($STATE['cur'] + 1);
		case 'GetChallengeInfo':
			foreach ($list as $c) if ($c['FileName'] === $params[0]) return $c;
			return new Fault(-1000, 'Challenge not found.');
		case 'GetChallengeList':
			$out = [];
			foreach ($list as $c) $out[] = ['Name' => $c['Name'], 'UId' => $c['UId'], 'FileName' => $c['FileName'],
			                                'Environnement' => $c['Environnement'], 'Author' => $c['Author'],
			                                'GoldTime' => $c['GoldTime'], 'CopperPrice' => $c['CopperPrice']];
			return array_slice($out, $params[1] ?? 0, $params[0] ?? 5000);
		case 'GetCurrentChallengeIndex': return $STATE['cur'] % count($list);
		case 'GetNextChallengeIndex': return ($STATE['cur'] + 1) % count($list);
		case 'GetCurrentRanking':
			$out = []; $rank = 1;
			foreach ($STATE['players'] as $p) $out[] = ['Login' => $p['Login'], 'NickName' => $p['NickName'],
			                                            'PlayerId' => $p['PlayerId'], 'Rank' => $rank++,
			                                            'BestTime' => $p['BestTime'] ?? -1, 'BestCheckpoints' => [],
			                                            'Score' => 0, 'NbrLapsFinished' => 0, 'LadderScore' => 0.0];
			return array_slice($out, $params[1] ?? 0, $params[0] ?? 300);
		case 'GetTimeAttackLimit': return cur_next(300000);
		case 'GetChatTime': return cur_next(10000);
		case 'GetRoundPointsLimit': return cur_next(50);
		case 'GetCupRoundsPerChallenge': return cur_next(5);
		case 'GetForceShowAllOpponents': return cur_next(0);
		case 'GetDisableRespawn': return cur_next(false);
		case 'GetRoundCustomPoints': return [10, 6, 4, 3, 2, 1];
		case 'GetCallVoteRatios': return [['Command' => '*', 'Ratio' => 0.5]];
		case 'GetLadderServerLimits': return ['LadderServerLimitMin' => 0, 'LadderServerLimitMax' => 50000];
		case 'GetNetworkStats': return ['Uptime' => 3600, 'NbrConnection' => 5, 'MeanConnectionTime' => 600,
		                                'MeanNbrPlayer' => 2, 'RecvNetRate' => 10, 'SendNetRate' => 10,
		                                'TotalReceivingSize' => 1000, 'TotalSendingSize' => 1000, 'PlayerNetInfos' => []];
		case 'GetForcedMusic': return ['Override' => false, 'Url' => '', 'File' => ''];
		case 'GetServerCoppers': return 500;
		case 'GetRefereeMode': return 0;
		case 'GetHideServer': return 0;
		case 'GetWarmUp': return false;
		case 'IsRelayServer': return false;
		case 'IsChallengeDownloadAllowed': return true;
		case 'CheckChallengeForCurrentServerParams': return true;
		case 'GetServerPackMask': return 'Stadium';
		case 'GetServerName': return '$s$f80Test Server';
		case 'GetServerComment': return 'harness';
		case 'GameDataDirectory': return dirname($tracksDir) . '/';
		case 'GetTracksDirectory': return $tracksDir;
		case 'GetChatLines': return [];
		case 'GetBanList': case 'GetBlackList': case 'GetGuestList': case 'GetIgnoreList':
			$l = array_values($STATE['lists'][substr($method, 3, -4)] ?? []);
			return array_slice($l, $params[1] ?? 0, $params[0] ?? 1000);
		case 'Ban': case 'BanAndBlackList': case 'BlackList': case 'AddGuest': case 'Ignore':
			$list = ['Ban' => 'Ban', 'BanAndBlackList' => 'Ban', 'BlackList' => 'Black', 'AddGuest' => 'Guest', 'Ignore' => 'Ignore'][$method];
			$e = ['Login' => $params[0]];
			if ($list === 'Ban') $e += ['ClientName' => $params[0], 'IPAddress' => '127.0.0.1'];
			$STATE['lists'][$list][$params[0]] = $e;
			if ($method === 'BanAndBlackList') $STATE['lists']['Black'][$params[0]] = ['Login' => $params[0]];
			return true;
		case 'UnBan': case 'UnBlackList': case 'RemoveGuest': case 'UnIgnore':
			$list = ['UnBan' => 'Ban', 'UnBlackList' => 'Black', 'RemoveGuest' => 'Guest', 'UnIgnore' => 'Ignore'][$method];
			if (!isset($STATE['lists'][$list][$params[0]])) return new Fault(-1000, 'Login unknown.');
			unset($STATE['lists'][$list][$params[0]]);
			return true;
		case 'CleanBanList': $STATE['lists']['Ban'] = []; return true;
		case 'CleanBlackList': $STATE['lists']['Black'] = []; return true;
		case 'CleanGuestList': $STATE['lists']['Guest'] = []; return true;
		case 'CleanIgnoreList': $STATE['lists']['Ignore'] = []; return true;
		case 'SaveMatchSettings': case 'LoadMatchSettings': return count($list);
		case 'SendBill':
			$STATE['bills'][] = $params;
			return 1000 + count($STATE['bills']);
		case 'RemoveChallenge':
			// the real server accepts paths relative to Tracks/ or absolute ones
			if (str_starts_with($params[0], $tracksDir)) $params[0] = substr($params[0], strlen($tracksDir));
			foreach ($GLOBALS['CHALLENGES'] as $i => $c)
				if ($c['FileName'] === $params[0] && count($GLOBALS['CHALLENGES']) > 1) {
					array_splice($GLOBALS['CHALLENGES'], $i, 1);
					return true;
				}
			logmsg('RemoveChallenge refused: ' . json_encode($params[0]) . ' list=' . json_encode(array_column($GLOBALS['CHALLENGES'], 'FileName')));
			return new Fault(-1000, 'Challenge not found.');
		case 'AddChallenge': case 'InsertChallenge':
			if (!is_file($tracksDir . $params[0]) && !is_file($params[0])) return new Fault(-1000, 'Challenge not found.');
			return true;
		case 'NextChallenge': case 'ChallengeRestart': case 'RestartChallenge':
			if ($method === 'NextChallenge') $GLOBALS['pendingChallengeSwitch'] = true;
			return true;
		case 'QuitGame': case 'StopServer':
			return new Fault(-1000, 'Not allowed in test harness.');
		default:
			if (str_starts_with($method, 'Get')) {
				logmsg("UNHANDLED getter $method " . json_encode($params));
				return new Fault(-1000, "Unhandled $method");
			}
			return true;   // setters, chat, manialinks, kick/ban...
	}
}

class Fault { function __construct(public $code, public $msg) {} }

// ---------------------------------------------------------------- scenario helpers (used by the scenario file)

function cb($method, ...$params) { $GLOBALS['OUT'][] = [$method, $params]; }

function add_player($login, $nick, $path = 'World|Portugal|Lisboa', $spec = false) {
	global $STATE;
	$STATE['players'][$login] = ['Login' => $login, 'NickName' => $nick, 'PlayerId' => $STATE['nextPlayerId']++,
	                             'Path' => $path, 'IsSpectator' => $spec, 'LadderRanking' => 1234];
	cb('TrackMania.PlayerConnect', $login, $spec);
}

function remove_player($login) {
	global $STATE;
	unset($STATE['players'][$login]);
	cb('TrackMania.PlayerDisconnect', $login);
}

// the player pays the newest bill (state 4 = Payed)
function pay_last_bill() {
	global $STATE;
	$n = count($STATE['bills'] ?? []);
	if ($n) cb('TrackMania.BillUpdated', 1000 + $n, 4, 'Payed', 5000 + $n);
}

function pid($login) { return $GLOBALS['STATE']['players'][$login]['PlayerId']; }

function chat($login, $text) {
	cb('TrackMania.PlayerChat', pid($login), $login, $text, str_starts_with($text, '/'));
}

function answer($login, $answer) {
	cb('TrackMania.PlayerManialinkPageAnswer', pid($login), $login, $answer);
}

function drive($login, $time) {
	global $STATE;
	$c = challenge();
	$n = max(1, $c['NbCheckpoints']);
	for ($i = 0; $i < $n; $i++)
		cb('TrackMania.PlayerCheckpoint', pid($login), $login, (int)($time * ($i + 1) / $n), 0, $i);
	cb('TrackMania.PlayerFinish', pid($login), $login, $time);
	$STATE['players'][$login]['BestTime'] = min($time, $STATE['players'][$login]['BestTime'] ?? PHP_INT_MAX);
}

function rankings() {
	global $STATE;
	$out = []; $rank = 1;
	$ps = $STATE['players'];
	uasort($ps, fn($a, $b) => ($a['BestTime'] ?? PHP_INT_MAX) <=> ($b['BestTime'] ?? PHP_INT_MAX));
	foreach ($ps as $p) $out[] = ['Login' => $p['Login'], 'NickName' => $p['NickName'], 'PlayerId' => $p['PlayerId'],
	                              'Rank' => $rank++, 'BestTime' => $p['BestTime'] ?? -1, 'BestCheckpoints' => [],
	                              'Score' => 0, 'NbrLapsFinished' => 0, 'LadderScore' => 0.0];
	return $out;
}

function end_race() {
	global $STATE;
	$STATE['status'] = [5, 'Finish'];
	cb('TrackMania.EndRace', rankings(), challenge());
	cb('TrackMania.StatusChanged', 5, 'Finish');
	cb('TrackMania.EndChallenge', rankings(), challenge(), false, false, false);
}

function begin_challenge($next = true) {
	global $STATE;
	if ($next) $STATE['cur']++;
	foreach ($STATE['players'] as &$p) unset($p['BestTime']);
	unset($p);
	$STATE['status'] = [3, 'Running - Synchronization'];
	cb('TrackMania.StatusChanged', 3, 'Running - Synchronization');
	cb('TrackMania.BeginRace', challenge());
	cb('TrackMania.BeginChallenge', challenge(), false, false);
	$STATE['status'] = [4, 'Running - Play'];
	cb('TrackMania.StatusChanged', 4, 'Running - Play');
}

// ---------------------------------------------------------------- main loop

require $argv[5] ?? __DIR__ . '/gbx_include.php';   // defines GBXChallMapFetcher
$GLOBALS['CHALLENGES'] = load_challenges($tracksDir, 10);
logmsg('challenges: ' . implode(', ', array_column($GLOBALS['CHALLENGES'], 'FileName')));

$scenario = require $scenarioFile;   // array of [delay_seconds, callable]

$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$server) die("listen failed: $errstr\n");
logmsg("listening on $port");
$client = stream_socket_accept($server, 600);
if (!$client) die("no client\n");
fwrite($client, pack('V', 11) . 'GBXRemote 2');
logmsg('client connected');

$buf = '';
$ready = false; $readyAt = 0; $step = 0; $nextAt = 0; $cbHandle = 1;
$GLOBALS['OUT'] = [];
while (true) {
	$r = [$client]; $w = null; $e = null;
	if (stream_select($r, $w, $e, 0, 100000) === false) break;
	if ($r) {
		$data = fread($client, 65536);
		if ($data === '' || $data === false) { if (feof($client)) { logmsg('client disconnected'); break; } }
		$buf .= $data;
	}
	while (strlen($buf) >= 8) {
		['size' => $size, 'handle' => $handle] = unpack('Vsize/Vhandle', $buf);
		if (strlen($buf) < 8 + $size) break;
		$xml = substr($buf, 8, $size);
		$buf = substr($buf, 8 + $size);
		[$method, $params] = xr_parse_call($xml);
		if (getenv('CALLS_FILE')) {
			$rec = ($method === 'system.multicall') ? $params[0] : [['methodName' => $method, 'params' => $params]];
			foreach ($rec as $c)
				file_put_contents(getenv('CALLS_FILE'), json_encode([$step, $c['methodName'], $c['params'] ?? []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
		}
		$res = handle_method($method, $params);
		$out = ($res instanceof Fault) ? xr_fault($res->code, $res->msg) : xr_response($res);
		fwrite($client, pack('VV', strlen($out), $handle) . $out);
		if ($method === 'EnableCallbacks' && !$ready) { $ready = true; $readyAt = microtime(true); $nextAt = $readyAt + 3; }
	}
	// play the scenario
	if ($ready && $step < count($scenario) && microtime(true) >= $nextAt) {
		[$delay, $fn] = $scenario[$step];
		logmsg("step $step");
		$fn();
		$step++;
		$nextAt = microtime(true) + ($scenario[$step][0] ?? 0);
	}
	if ($ready && $step >= count($scenario) && !$GLOBALS['OUT'] && empty($done) && microtime(true) > $nextAt + 5) {
		logmsg('scenario done');   // keep serving until XAseco is stopped
		$done = true;
		file_put_contents(dirname($logFile) . '/scenario.done', '1');
	}
	// a NextChallenge request ends the race like the real server would
	if (!empty($GLOBALS['pendingChallengeSwitch'])) {
		$GLOBALS['pendingChallengeSwitch'] = false;
		end_race();
		begin_challenge();
	}
	while ($GLOBALS['OUT']) {
		[$m, $p] = array_shift($GLOBALS['OUT']);
		$x = xr_call($m, $p);
		fwrite($client, pack('VV', strlen($x), $cbHandle++) . $x);
	}
}
fclose($client);
