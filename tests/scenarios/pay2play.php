<?php
// pay2play: double clicks while a bill is pending, and after paying
return [
	[1, fn() => add_player('testadmin', '$f00Test$fffAdmin')],
	[1, fn() => add_player('player2', 'Player $0f0Two', 'World|Germany|Berlin')],
	[2, fn() => answer('player2', 1289101)],     // skip: bill 1
	[0.3, fn() => answer('player2', 1289101)],   // double click: must not bill again
	[1, fn() => pay_last_bill()],                // pays bill 1
	[1, fn() => answer('testadmin', 1289101)],   // already paid: refused
	[1, fn() => answer('player2', 1289100)],     // replay: bill 2
	[0.3, fn() => answer('testadmin', 1289100)], // pending: refused
	[1, fn() => pay_last_bill()],                // pays bill 2
	[1, fn() => answer('player2', 1289100)],     // already replayed: refused
	[2, fn() => chat('player2', 'done')],
];
