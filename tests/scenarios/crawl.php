<?php
// Click crawl: players drive (so there are records, karma, medals), then every
// known button (buttons.json, harvested from earlier runs' windows) is
// clicked by the MasterAdmin and by a regular player.
$buttons = json_decode(file_get_contents(__DIR__ . '/data/buttons.json'), true);
$d = 0.25;
$s = [];
$s[] = [1, fn() => add_player('testadmin', '$f00Test$fffAdmin')];
$s[] = [1, fn() => add_player('player2', 'Player $0f0Two', 'World|Germany|Berlin')];
$s[] = [1, fn() => drive('player2', 15230)];
$s[] = [1, fn() => drive('testadmin', 14110)];
$s[] = [1, fn() => chat('testadmin', '/++')];
$s[] = [1, fn() => chat('player2', '/-')];
$s[] = [1, fn() => end_race()];
$s[] = [3, fn() => begin_challenge()];
$s[] = [2, fn() => drive('player2', 9900)];
$i = 0;
foreach (array_keys($buttons) as $id) {
	$id = (int)$id;
	$s[] = [$d, fn() => answer('testadmin', $id)];
	$s[] = [$d, fn() => answer('player2', $id)];
	if (++$i % 80 == 0) {   // the race goes on meanwhile
		$s[] = [1, fn() => end_race()];
		$s[] = [2, fn() => begin_challenge()];
		$s[] = [1, fn() => drive('testadmin', 9800)];
	}
}
$s[] = [1, fn() => end_race()];
$s[] = [3, fn() => begin_challenge()];
$s[] = [1, fn() => remove_player('player2')];
return $s;
