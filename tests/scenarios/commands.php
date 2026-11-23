<?php
// Full scenario: players drive, then every chat command and subcommand is
// sent by the MasterAdmin (with and without an argument) and by a regular
// player (permission paths), with race ends and challenge changes between.
$read = fn($f) => array_values(array_filter(array_map('trim', file(__DIR__ . "/data/$f"))));
$cmds  = $read('cmds.txt');
$subs  = ['admin' => $read('admin.txt'), 'jfreu' => $read('jfreu.txt'),
          'music' => $read('music.txt'), 'list' => $read('list.txt')];
$d = 0.25;  // seconds between commands

$s = [];
$s[] = [1, fn() => add_player('testadmin', '$f00Test$fffAdmin')];
$s[] = [1, fn() => add_player('player2', 'Player $0f0Two', 'World|Germany|Berlin')];
$s[] = [1, fn() => add_player('spec3', '$iSpec Three', 'World|France|Paris', true)];
$s[] = [1, fn() => drive('player2', 15230)];
$s[] = [1, fn() => drive('testadmin', 14110)];
$s[] = [1, fn() => drive('player2', 13990)];
$s[] = [2, fn() => end_race()];
$s[] = [3, fn() => begin_challenge()];
$s[] = [2, fn() => drive('testadmin', 9800)];

$i = 0;
foreach ($cmds as $c) {
	if (isset($subs[$c])) continue;
	foreach (['', ' 1', ' player2'] as $arg)
		$s[] = [$d, fn() => chat('testadmin', "/$c$arg")];
	$s[] = [$d, fn() => chat('player2', "/$c")];
	if (++$i % 60 == 0) {   // the race goes on meanwhile
		$s[] = [1, fn() => drive('player2', 9700)];
		$s[] = [1, fn() => end_race()];
		$s[] = [2, fn() => begin_challenge()];
	}
}
foreach ($subs as $c => $list) {
	foreach ($list as $sub) {
		foreach (['', ' 1', ' player2', ' on'] as $arg)
			$s[] = [$d, fn() => chat('testadmin', "/$c $sub$arg")];
		$s[] = [$d, fn() => chat('player2', "/$c $sub")];
	}
}
// list windows: page through and click entries
foreach ([1, 2, 3, 4, 5, 6, 7, 8, 101, 102, 103, -101, -102, 2001, 2002, 2201, 2401, 2601, 2801, 3001, 3201, 3401, 3601, 3801] as $a)
	$s[] = [$d, fn() => answer('testadmin', $a)];
$s[] = [1, fn() => chat('player2', 'gg everyone')];
$s[] = [1, fn() => end_race()];
$s[] = [3, fn() => begin_challenge()];
$s[] = [1, fn() => remove_player('spec3')];
$s[] = [1, fn() => remove_player('player2')];
return $s;
