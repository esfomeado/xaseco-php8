<?php
// players online while the database restarts: run with DB_RESTART_AFTER=18
return [
	[1, fn() => add_player('testadmin', '$f00Test$fffAdmin')],
	[1, fn() => add_player('player2', 'Player $0f0Two', 'World|Germany|Berlin')],
	[1, fn() => drive('player2', 15230)],
	[25, fn() => drive('testadmin', 14110)],
	[1, fn() => chat('testadmin', '/stats')],
	[1, fn() => chat('player2', '/rank')],
	[1, fn() => chat('testadmin', '/recs')],
	[2, fn() => end_race()],
	[3, fn() => begin_challenge()],
];
