<?php
require_once realpath(dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'bootstrap.php');

use nzedb\Categorize;
use nzedb\Category;
use nzedb\ColorCLI;
use nzedb\ConsoleTools;
use nzedb\db\DB;

$pdo = new DB();

if (!(isset($argv[1]) && ($argv[1] == "all" || $argv[1] == "misc" || preg_match('/\([\d, ]+\)/', $argv[1]) || is_numeric($argv[1])))) {
	exit($pdo->log->error(
		"\nThis script will attempt to re-categorize releases and is useful if changes have been made to Category.php.\n"
		. "No updates will be done unless the category changes\n"
		. "An optional last argument, test, will display the number of category changes that would be made\n"
		. "but will not update the database.\n\n"
		. "php $argv[0] all                     ...: To process all releases.\n"
		. "php $argv[0] misc                    ...: To process all releases in misc categories.\n"
		. "php $argv[0] 155                     ...: To process all releases with group id 155.\n"
		. "php $argv[0] '(155, 140)'            ...: To process all releases with group ids 155 and 140.\n"
	));
}

reCategorize($argv);

function reCategorize($argv)
{
	global $pdo;
	$where = '';
	$othercats = Category::getCategoryOthersGroup();
	$update = true;
	if (isset($argv[1]) && is_numeric($argv[1])) {
		$where = ' AND groups_id = ' . $argv[1];
	} else if (isset($argv[1]) && preg_match('/\([\d, ]+\)/', $argv[1])) {
		$where = ' AND groups_id IN ' . $argv[1];
	} else if (isset($argv[1]) && $argv[1] === 'misc') {
		$where = sprintf(' AND categories_id IN (%s)', $othercats);
	}
	if (isset($argv[2]) && $argv[2] === 'test') {
		$update = false;
	}

	if (isset($argv[1]) && (is_numeric($argv[1]) || preg_match('/\([\d, ]+\)/', $argv[1]))) {
		echo $pdo->log->header("Categorizing all releases in ${argv[1]} using searchname. This can take a while, be patient.");
	} else if (isset($argv[1]) && $argv[1] == "misc") {
		echo $pdo->log->header("Categorizing all releases in misc categories using searchname. This can take a while, be patient.");
	} else {
		echo $pdo->log->header("Categorizing all releases using searchname. This can take a while, be patient.");
	}
	$timestart = TIME();
	if (isset($argv[1]) && (is_numeric($argv[1]) || preg_match('/\([\d, ]+\)/', $argv[1])) || $argv[1] === 'misc') {
		$chgcount = categorizeRelease(str_replace(" AND", "WHERE", $where), $update, true);
	} else {
		$chgcount = categorizeRelease('', $update, true);
	}
	$consoletools = new ConsoleTools(['ColorCLI' => $pdo->log]);
	$time = $consoletools->convertTime(TIME() - $timestart);
	if ($update === true) {
		echo $pdo->log->header("Finished re-categorizing " . number_format($chgcount) . " releases in " . $time . " , using the searchname.\n");
	} else {
		echo $pdo->log->header("Finished re-categorizing in " . $time . " , using the searchname.\n"
		. "This would have changed " . number_format($chgcount) . " releases but no updates were done.\n");
	}
}

// Categorizes releases.
// Returns the quantity of categorized releases.
function categorizeRelease($where, $update = true, $echooutput = false)
{
	global $pdo;
	$cat = new Categorize(['Settings' => $pdo]);
	$pdo->log = new ColorCLI();
	$consoletools = new ConsoleTools(['ColorCLI' => $pdo->log]);
	$relcount = $chgcount = 0;
	echo $pdo->log->primary("SELECT id, searchname, fromname, groups_id, categories_id FROM releases " . $where);
	$resrel = $pdo->queryDirect("SELECT id, searchname, fromname, groups_id, categories_id FROM releases " . $where);
	$total = $resrel->rowCount();
	if ($total > 0) {
		foreach ($resrel as $rowrel) {
			$catId = $cat->determineCategory($rowrel['groups_id'], $rowrel['searchname'], $rowrel['fromname']);
			if ($rowrel['categories_id'] != $catId) {
				if ($update === true) {
					$pdo->queryExec(
						sprintf("
							UPDATE releases
							SET iscategorized = 1,
								videos_id = 0,
								tv_episodes_id = 0,
								imdbid = NULL,
								musicinfo_id = NULL,
								consoleinfo_id = NULL,
								gamesinfo_id = 0,
								bookinfo_id = NULL,
								anidbid = NULL,
								xxxinfo_id = 0,
								categories_id = %d
							WHERE id = %d",
							$catId,
							$rowrel['id']
						)
					);
				}
				$chgcount++;
			}
			$relcount++;
			if ($echooutput) {
				$consoletools->overWritePrimary("Re-Categorized: [" . number_format($chgcount) . "] " . $consoletools->percentString($relcount, $total));
			}
		}
	}
	if ($echooutput !== false && $relcount > 0) {
		echo "\n";
	}
	return $chgcount;
}
