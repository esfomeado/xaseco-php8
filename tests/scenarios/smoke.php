<?php
// smoke: two players join, chat, drive, race ends, next challenge
return [
	[1, fn() => add_player('testadmin', '$f00Test$fffAdmin')],
	[1, fn() => add_player('player2', 'Player $0f0Two', 'World|Germany|Berlin')],
	[2, fn() => chat('player2', 'hello all')],
	[1, fn() => drive('player2', 15230)],
	[1, fn() => drive('testadmin', 14110)],
	[1, fn() => chat('testadmin', '/help')],
	[2, fn() => end_race()],
	[3, fn() => begin_challenge()],
	[3, fn() => remove_player('player2')],
];
