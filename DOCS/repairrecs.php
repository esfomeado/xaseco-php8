#!/usr/bin/php -q
<?php
// vim: set noexpandtab tabstop=2 softtabstop=2 shiftwidth=2:

// Repair XASECO records table inconsistencies with rs_times table
// Created Dec 2007 by Xymph <tm@gamers.org>
// Updated Apr 2012 by Xymph <tm@gamers.org>

	function stripColors($str) {
		return
			str_replace("\0", '$$',
				preg_replace(
					'/\\$(?:[0-9a-f]..|[g-z]|$)/iu', '',
					str_replace('$$', "\0", $str)
				)
			)
		;
	}

	date_default_timezone_set(@date_default_timezone_get());
	$maxrecs = 50;

	mysqli_report(MYSQLI_REPORT_OFF);
	$db = new mysqli('localhost','YOUR_MYSQL_LOGIN','YOUR_MYSQL_PASSWORD');
	if ($db->connect_errno) {
		echo "could not connect\n";
		exit;
	}
	if (!$db->select_db('aseco')) {
		echo "could not select\n";
		exit;
	}

	$query = 'SELECT id,name FROM challenges ORDER BY id';
	$reschl = $db->query($query);

	if ($reschl->num_rows > 0) {
		echo 'Selected challenges: ' . $reschl->num_rows . "\n\n";

		$tracks = 0;
		$trackid = 0;
		$add = 0;
		$upd = 0;
		$list = array();
		while ($rowchl = $reschl->fetch_object()) {

			$query = 'SELECT playerid,score,date FROM records WHERE ChallengeID=' . $rowchl->id .
			         ' ORDER BY score,date LIMIT ' . $maxrecs;
			$resrec = $db->query($query);

			$query = 'SELECT DISTINCT playerid,score FROM rs_times t1 WHERE challengeid=' . $rowchl->id .
			         ' AND score=(SELECT MIN(t2.score) FROM rs_times t2 WHERE challengeid=' . $rowchl->id .
			         '            AND t1.playerid=t2.playerid) ORDER BY score,date LIMIT ' . $maxrecs;
			$restms = $db->query($query);

			if ($resrec->num_rows > 0) {
				$n = 1;
				while ($rowrec = $resrec->fetch_object()) {
					$rowtms = $restms->fetch_object();
					if ($rowtms === false) {
						printf("%3d : %32s\t-> rec %3d: no more rs_times entries - consistency error!\n", $rowchl->id, stripColors($rowchl->name), $n);
						break;
					}

					// consistency check
					if ($rowrec->playerid != $rowtms->playerid ||
					    $rowrec->score != $rowtms->score) {
						// fetch corresponding date/time & checkpoints
						$query = 'SELECT date,checkpoints FROM rs_times WHERE challengeid=' . $rowchl->id .
						         ' AND playerid=' . $rowtms->playerid . ' ORDER BY score,date LIMIT 1';
						$resdat = $db->query($query);
						$rowdat = $resdat->fetch_object();
						if ($resdat) $resdat->free();
						$newdat = date('Y-m-d H:i:s', $rowdat->date);

//						printf("%3d : %32s\t-> rec %3d: %5d/%5d differs from %d/%5d\n", $rowchl->id, stripColors($rowchl->name), $n, $rowrec->playerid, $rowrec->score, $rowtms->playerid, $rowtms->score);

						$query = "INSERT INTO records
						          (ChallengeId, PlayerId, Score, Date, Checkpoints)
						          VALUES
						          (" . $rowchl->id . ", " . $rowtms->playerid . ", " .
						           $rowtms->score . ", '" . $newdat . "', '" .
						           $rowdat->checkpoints . "')";
						$result = $db->query($query);

						// couldn't be inserted? then player had a record already
						if ($db->affected_rows != 1) {

							$query = "UPDATE records
							          SET Score=" . $rowtms->score . ", Checkpoints='" . $rowdat->checkpoints . "', Date='" . $newdat . "'
							          WHERE ChallengeId=" . $rowchl->id . " AND PlayerId=" . $rowtms->playerid;
							$result = $db->query($query);

							// couldn't be updated? then something's going wrong
							if ($db->affected_rows == -1) {
								echo $db->errno . ': ' . $db->error . "\n";
								exit;
							} elseif ($db->affected_rows == 0) {
//								printf("%3d : %32s\t-> rec %3d: skipped %d5/%5d %s\n", $rowchl->id, stripColors($rowchl->name), $n, $rowtms->playerid, $rowtms->score, $newdat);
							} else { // mysql_affected_rows() == 1
								printf("%3d : %32s\t-> rec %3d: updated %5d/%5d %s\n", $rowchl->id, stripColors($rowchl->name), $n, $rowtms->playerid, $rowtms->score, $newdat);
								$upd++;
								if ($trackid != $rowchl->id) {
									$trackid = $rowchl->id;
									$tracks++;
								}
							}
						} else { // mysql_affected_rows() == 1
							printf("%3d : %32s\t-> rec %3d: added   %5d/%5d %s\n", $rowchl->id, stripColors($rowchl->name), $n, $rowtms->playerid, $rowtms->score, $newdat);
							$add++;
							if ($trackid != $rowchl->id) {
								$trackid = $rowchl->id;
								$tracks++;
							}
						}
					}
					$n++;
				}
			}

			if ($resrec) $resrec->free();
			if ($restms) $restms->free();
		}

		echo "\n" . $add . ' added & ' . $upd . ' updated records on ' . $tracks . " tracks\n";
		if ($reschl) $reschl->free();
	} else {
		echo "no challenges!\n";
	}
?>
