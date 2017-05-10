#!/usr/bin/env php
<?php
/**
 * tsmarty2c.php - rips gettext strings from smarty template
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * This commandline script rips gettext strings from smarty file,
 * and prints them to stdout in already gettext encoded format, which you can
 * later manipulate with standard gettext tools.
 *
 * Usage:
 * ./tsmarty2c.php -o template.pot <filename or directory> <file2> <..>
 *
 * If a parameter is a directory, the template files within will be parsed.
 *
 * @package   smarty-gettext
 * @link      https://github.com/smarty-gettext/smarty-gettext/
 * @author    Sagi Bashari <sagi@boom.org.il>
 * @author    Elan Ruusamäe <glen@delfi.ee>
 * @copyright 2004-2005 Sagi Bashari
 * @copyright 2010-2015 Elan Ruusamäe
 */

// smarty open tag
$ldq = preg_quote('{');

// smarty close tag
$rdq = preg_quote('}');

// smarty command
$cmd = preg_quote('t');

// extensions of smarty files, used when going through a directory
$extensions = array('tpl');

// we msgcat found strings from each file.
// need header for each temporary .pot file to be merged.
// https://help.launchpad.net/Translations/YourProject/PartialPOExport
define('MSGID_HEADER', 'msgid ""
msgstr "Content-Type: text/plain; charset=UTF-8\n"

');

// Defining global value of $domain
global $domain;
$domain = '';

// "fix" string - strip slashes, escape and convert new lines to \n
function fs($str) {
	$str = stripslashes($str);
	$str = str_replace('"', '\"', $str);
	$str = str_replace("\n", '\n', $str);

	return $str;
}

function lineno_from_offset($content, $offset) {
	return substr_count($content, "\n", 0, $offset) + 1;
}

function msgmerge($outfile, $data) {
	// skip empty
	if (empty($data)) {
		return;
	}

	// write new data to tmp file
	$tmp = tempnam(TMPDIR, 'tsmarty2c');
	file_put_contents($tmp, $data);

	// temp file for result cat
	$tmp2 = tempnam(TMPDIR, 'tsmarty2c');
	passthru('msgcat -o ' . escapeshellarg($tmp2) . ' ' . escapeshellarg($outfile) . ' ' . escapeshellarg($tmp), $rc);
	unlink($tmp);

	if ($rc) {
		fwrite(STDERR, "msgcat failed with $rc\n");
		exit($rc);
	}

	// rename if output was produced
	if (file_exists($tmp2)) {
		rename($tmp2, $outfile);
	}
}

// rips gettext strings from $file and prints them in C format
function do_file($outfile, $file) {
    global $domain;
	$content = file_get_contents($file);

	if (empty($content)) {
		return;
	}

	global $ldq, $rdq, $cmd;

	preg_match_all(
		"/{$ldq}\s*({$cmd})\s*([^{$rdq}]*){$rdq}+([^{$ldq}]*){$ldq}\/\\1{$rdq}/",
		$content,
		$matches,
		PREG_OFFSET_CAPTURE
	);

    $result_msgdomain = array(); //msgdomain -> msgid based content
	$result_msgctxt = array(); //msgctxt -> msgid based content
	$result_msgid = array(); //only msgid based content
	for ($i = 0; $i < count($matches[0]); $i++) {
        $msg_domain = null;
		$msg_ctxt = null;
		$plural = null;

        if (preg_match('/domain\s*=\s*["\']?\s*(.[^\"\']*)\s*["\']?/', $matches[2][$i][0], $match)) {
            if($domain == ''){  // Ignore strings with domain, if domain argument (-d) missing or not provided while executing script
                continue;
            }elseif($domain != '' && $match[1] == $domain){ // Only pick strings where domain matches with domain argument provided
                $msg_domain = $match[1];
            }
        }

		if (preg_match('/context\s*=\s*["\']?\s*(.[^\"\']*)\s*["\']?/', $matches[2][$i][0], $match)) {
			$msg_ctxt = $match[1];
		}

		if (preg_match('/plural\s*=\s*["\']?\s*(.[^\"\']*)\s*["\']?/', $matches[2][$i][0], $match)) {
			$msgid = $matches[3][$i][0];
			$plural = $match[1];
		} else {
			$msgid = $matches[3][$i][0];
		}

        if ($msg_domain && empty($result_msgdomain[$msg_domain])) {
            $result_msgdomain[$msg_domain] = array();
        }

		if ($msg_ctxt && empty($result_msgctxt[$msg_ctxt])) {
			$result_msgctxt[$msg_ctxt] = array();
		}

        if ($msg_domain && empty($result_msgdomain[$msg_domain][$msgid])) {
            $result_msgdomain[$msg_domain][$msgid] = array();
        }elseif ($msg_ctxt && empty($result_msgctxt[$msg_ctxt][$msgid])) {
			$result_msgctxt[$msg_ctxt][$msgid] = array();
		} elseif (empty($result_msgid[$msgid])) {
			$result_msgid[$msgid] = array();
		}

		if ($plural) {
            if ($msg_domain) {
                $result_msgdomain[$msg_domain][$msgid]['plural'] = $plural;
            }elseif ($msg_ctxt) {
				$result_msgctxt[$msg_ctxt][$msgid]['plural'] = $plural;
			} else {
				$result_msgid[$msgid]['plural'] = $plural;
			}
		}

		$lineno = lineno_from_offset($content, $matches[2][$i][1]);
        if ($msg_domain) {
            $result_msgdomain[$msg_domain][$msgid]['lineno'][] = "$file:$lineno";
        }elseif ($msg_ctxt) {
			$result_msgctxt[$msg_ctxt][$msgid]['lineno'][] = "$file:$lineno";
		} else {
			$result_msgid[$msgid]['lineno'][] = "$file:$lineno";
		}
	}

	ob_start();
	echo MSGID_HEADER;
    global $domain;
    if( isset($domain) && $domain != '' ){
        foreach($result_msgdomain as $msgdomain => $data_msgid) {
            foreach($data_msgid as $msgid => $data) {
                echo "#: ", join(' ', $data['lineno']), "\n";
                echo 'msgid "' . fs($msgid) . '"', "\n";
                if (isset($data['plural'])) {
                    echo 'msgid_plural "' . fs($data['plural']) . '"', "\n";
                    echo 'msgstr[0] ""', "\n";
                    echo 'msgstr[1] ""', "\n";
                } else {
                    echo 'msgstr ""', "\n";
                }
                echo "\n";
            }
        }
    }else{
        foreach($result_msgctxt as $msgctxt => $data_msgid) {
            foreach($data_msgid as $msgid => $data) {
                echo "#: ", join(' ', $data['lineno']), "\n";
                echo 'msgctxt "' . fs($msgctxt) . '"', "\n";
                echo 'msgid "' . fs($msgid) . '"', "\n";
                if (isset($data['plural'])) {
                    echo 'msgid_plural "' . fs($data['plural']) . '"', "\n";
                    echo 'msgstr[0] ""', "\n";
                    echo 'msgstr[1] ""', "\n";
                } else {
                    echo 'msgstr ""', "\n";
                }
                echo "\n";
            }
        }
        //without msgctxt
        foreach($result_msgid as $msgid => $data) {
            echo "#: ", join(' ', $data['lineno']), "\n";
            echo 'msgid "' . fs($msgid) . '"', "\n";
            if (isset($data['plural'])) {
                echo 'msgid_plural "' . fs($data['plural']) . '"', "\n";
                echo 'msgstr[0] ""', "\n";
                echo 'msgstr[1] ""', "\n";
            } else {
                echo 'msgstr ""', "\n";
            }
            echo "\n";
        }
    }

	$out = ob_get_contents();
	ob_end_clean();
	msgmerge($outfile, $out);
}

// go through a directory
function do_dir($outfile, $dir) {
	$d = dir($dir);

	while (false !== ($entry = $d->read())) {
		if ($entry == '.' || $entry == '..') {
			continue;
		}

		$entry = $dir . '/' . $entry;

		if (is_dir($entry)) { // if a directory, go through it
			do_dir($outfile, $entry);
		} else { // if file, parse only if extension is matched
			$pi = pathinfo($entry);

			if (isset($pi['extension']) && in_array($pi['extension'], $GLOBALS['extensions'])) {
				do_file($outfile, $entry);
			}
		}
	}

	$d->close();
}

if ('cli' != php_sapi_name()) {
	error_log("ERROR: This program is for command line mode only.");
	exit(1);
}

define('PROGRAM', basename(array_shift($argv)));
define('TMPDIR', sys_get_temp_dir());
$opt = getopt('d:o:');
$outfile = isset($opt['o']) ? $opt['o'] : tempnam(TMPDIR, 'tsmarty2c');

// remove -o FILENAME from $argv.
if (isset($opt['o'])) {
	foreach ($argv as $i => $v) {
		if ($v != '-o') {
			continue;
		}

		unset($argv[$i]);
		unset($argv[$i + 1]);
		break;
	}
}

// remove -d domain from $argv.
if (isset($opt['d']) && trim($opt['d']) != '-o') {
    $domain = trim($opt['d']);
    foreach ($argv as $i => $v) {
        if ($v != '-d') {
            continue;
        }

        unset($argv[$i]);
        unset($argv[$i + 1]);
        break;
    }
}

// initialize output
file_put_contents($outfile, MSGID_HEADER);

// process dirs/files
foreach ($argv as $arg) {
	if (is_dir($arg)) {
		do_dir($outfile, $arg);
	} else {
		do_file($outfile, $arg);
	}
}

// output and cleanup
if (!isset($opt['o'])) {
	echo file_get_contents($outfile);
	unlink($outfile);
}
