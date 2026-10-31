<?php
/**
 * PreToolUse guard: keeps agents from weakening the verification layer.
 *
 * Exit 2 blocks the tool call and shows STDERR to the agent. Exit 0 allows it.
 *
 * Locked:
 *   - verification config: phpunit.xml(.dist), composer.json/.lock, .gitignore,
 *     bin/install-wp-tests.sh, .github/**, .claude/settings*.json,
 *     .claude/hooks/**, vendor/**, ~/.wp-tests/**, ~/.claude/settings*.json
 *   - tests/** files that exist in refs/verify/baseline or in HEAD
 *   - outside the checkout: the gate's state (~/.claude/verification-gate/,
 *     where green results and unlocks are recorded) and the orchestrate
 *     skill's worker/reviewer definitions (the reviewer is a check)
 *
 * Editable: a file under tests/ that is in neither — a test the agent created
 * and that has not been committed yet. A worker must be able to fix its own
 * new test, or one mistake in it kills the phase.
 *
 * The one way through is the person: a prompt whose first line is exactly
 * UNLOCK-VERIFICATION unlocks the main session for that turn (verify.sh
 * `prompt` writes the marker, `stop` removes it). Subagents are never
 * unlocked. Same rule as ai-section-builder-modern's guard.js.
 *
 * This is the early warning, not the authority. Bash is matched by pattern,
 * so a determined command can get past it; verify.sh compares every tracked
 * protected path against refs/verify/baseline at turn end, whatever wrote it.
 *
 * Fails closed: settings run this with onFailure "block", and input it cannot
 * read is refused rather than waved through.
 */

$root      = dirname( __DIR__, 2 );
$home      = getenv( 'HOME' );
$state_dir = $home . '/.claude/verification-gate';
$input     = json_decode( stream_get_contents( STDIN ), true );

if ( ! is_array( $input ) ) {
	fwrite( STDERR, "BLOCKED by the verification guard: its input could not be read.\n" );
	exit( 2 );
}

// The person's unlock: main session only, this turn only, never past 2 hours.
if ( empty( $input['agent_id'] ) && ! empty( $input['session_id'] ) ) {
	$marker = $state_dir . '/form-runtime-engine/unlock-' . basename( (string) $input['session_id'] );
	if ( is_file( $marker ) ) {
		$until = (int) strtok( (string) file_get_contents( $marker ), "\n" );
		$left  = $until - (int) round( microtime( true ) * 1000 );
		if ( $left > 0 && $left <= 2 * 60 * 60 * 1000 ) {
			exit( 0 );
		}
	}
}

$tool = isset( $input['tool_name'] ) ? $input['tool_name'] : '';
$args = isset( $input['tool_input'] ) ? $input['tool_input'] : array();
$cwd  = ! empty( $input['cwd'] ) && is_dir( $input['cwd'] ) ? $input['cwd'] : $root;

/**
 * Block the call.
 *
 * @param string $what   What was refused.
 * @param string $reason Why it is locked.
 */
function deny( $what, $reason ) {
	fwrite(
		STDERR,
		"BLOCKED by the verification guard: {$what}\n"
		. "Reason: {$reason}.\n\n"
		. "Tests that exist in the baseline or are committed, phpunit.xml, composer.json, CI, the WP test\n"
		. "library and .claude/settings + hooks are locked so a failing check cannot be weakened to pass.\n"
		. "Tests you created and have not committed stay editable. If a locked check is genuinely\n"
		. "wrong, do not work around it: stop and say which check and why, so the user can change it.\n"
	);
	exit( 2 );
}

/**
 * Normalize a path without requiring it to exist.
 *
 * @param string $path Absolute or relative path.
 * @param string $base Directory relative paths resolve against.
 * @return string Absolute path.
 */
function normalize( $path, $base ) {
	global $home;

	if ( 0 === strpos( $path, '~/' ) || '~' === $path ) {
		$path = $home . substr( $path, 1 );
	} elseif ( '/' !== substr( $path, 0, 1 ) ) {
		$path = $base . '/' . $path;
	}

	$parts = array();
	foreach ( explode( '/', $path ) as $part ) {
		if ( '' === $part || '.' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			array_pop( $parts );
			continue;
		}
		$parts[] = $part;
	}

	return '/' . implode( '/', $parts );
}

/**
 * Is a git object present at this ref?
 *
 * @param string $ref Ref.
 * @param string $rel Repo-relative path.
 * @return bool
 */
function in_ref( $ref, $rel ) {
	global $root;

	$cmd = 'git -C ' . escapeshellarg( $root ) . ' cat-file -e ' . escapeshellarg( $ref . ':' . $rel ) . ' 2>/dev/null';
	exec( $cmd, $out, $code );

	return 0 === $code;
}

/**
 * Why a path is locked, or null when it may be written.
 *
 * @param string $abs Absolute path.
 * @return string|null
 */
function lock_reason( $abs ) {
	global $root, $home;

	if ( 0 === strpos( $abs, $home . '/.wp-tests/' ) || $abs === $home . '/.wp-tests' ) {
		return 'the WordPress test library and its config are verification infrastructure';
	}
	if ( preg_match( '#^' . preg_quote( $home, '#' ) . '/\.claude/settings[^/]*\.json$#', $abs ) ) {
		return 'user-level Claude settings can disable hooks';
	}
	if ( 0 === strpos( $abs . '/', $home . '/.claude/verification-gate/' ) ) {
		return 'the gate state records green results and unlocks';
	}
	if ( preg_match( '#^' . preg_quote( $home, '#' ) . '/\.claude/(agents/phase-(worker|reviewer)\.md$|skills/orchestrate(/|$))#', $abs ) ) {
		return 'the orchestrate worker and reviewer definitions are part of the checking';
	}

	if ( $abs === $root ) {
		return null;
	}
	if ( 0 !== strpos( $abs, $root . '/' ) ) {
		return null;
	}

	$rel = substr( $abs, strlen( $root ) + 1 );

	if ( preg_match( '#^\.git/(refs/verify(/|$)|packed-refs$)#', $rel ) ) {
		return 'the baseline ref is moved only by the user';
	}

	if ( preg_match( '#^(phpunit\.xml(\.dist)?|composer\.(json|lock)|\.gitignore|bin/install-wp-tests\.sh)$#', $rel ) ) {
		return "{$rel} is verification config";
	}
	if ( preg_match( '#^(\.github|\.claude/hooks|vendor)(/|$)#', $rel ) || preg_match( '#^\.claude/settings[^/]*\.json$#', $rel ) || '.claude' === $rel ) {
		return "{$rel} is part of the verification layer";
	}

	if ( 'tests' === $rel || 0 === strpos( $rel, 'tests/' ) ) {
		if ( is_dir( $abs ) ) {
			return "{$rel}/ contains existing tests";
		}
		if ( in_ref( 'refs/verify/baseline', $rel ) ) {
			return "{$rel} is an existing test (present in the verification baseline)";
		}
		if ( in_ref( 'HEAD', $rel ) ) {
			return "{$rel} is a committed test";
		}
	}

	return null;
}

// File tools.
if ( in_array( $tool, array( 'Edit', 'Write', 'MultiEdit', 'NotebookEdit' ), true ) ) {
	$path = isset( $args['file_path'] ) ? $args['file_path'] : ( isset( $args['notebook_path'] ) ? $args['notebook_path'] : '' );

	if ( '' !== $path ) {
		$reason = lock_reason( normalize( $path, $cwd ) );
		if ( null !== $reason ) {
			deny( "{$tool} {$path}", $reason );
		}
	}

	exit( 0 );
}

if ( 'Bash' !== $tool ) {
	exit( 0 );
}

$cmd = isset( $args['command'] ) ? (string) $args['command'] : '';

// The baseline ref and the rebaseline script are the user's alone. Judged per
// command, so reading the ref (git rev-parse, git diff, grep in a file) is
// allowed inside a longer command line; anything else that names it is not.
foreach ( preg_split( '#\s*(?:&&|\|\||[;&|\n])\s*#', $cmd ) as $segment ) {
	if ( ! preg_match( '#refs/verify|rebaseline#', $segment ) ) {
		continue;
	}
	$is_read = preg_match( '#^\s*(\w+=\S*\s+)*git\s+(-C\s+("[^"]*"|\'[^\']*\'|\S+)\s+)*(rev-parse|log|diff|show|cat-file|ls-tree|for-each-ref|merge-base|reflog\s+show)\b#', $segment )
		|| preg_match( '#^\s*(grep|rg|cat|head|tail|less|wc)\s#', $segment );
	if ( ! $is_read ) {
		deny( $cmd, 'refs/verify/baseline is moved only by the user (.claude/hooks/rebaseline.sh in their terminal, or a turn that starts with UNLOCK-VERIFICATION)' );
	}
}
if ( preg_match( '#disableAllHooks|core\.hooksPath#', $cmd ) ) {
	deny( $cmd, 'commands that switch hooks off are not allowed' );
}

// Heredoc bodies are file content, not commands.
$cmd = preg_replace( '#<<-?\s*([\'"]?)(\w+)\1.*?\n.*?\n\s*\2\s*(\n|$)#s', "<<HEREDOC\n", $cmd );

/**
 * Refuse when any of these words names a locked path.
 *
 * @param array  $words Candidate paths.
 * @param string $cmd   Full command, for the message.
 */
function check_words( array $words, $cmd ) {
	global $cwd;

	foreach ( $words as $word ) {
		$word = trim( $word, '\'"' );
		if ( '' === $word || '-' === $word[0] || false !== strpos( $word, '://' ) || false !== strpos( $word, '$' ) ) {
			continue;
		}

		$candidates = array( normalize( $word, $cwd ) );
		if ( false !== strpbrk( $word, '*?[' ) ) {
			$base       = '/' === $word[0] ? '' : $cwd . '/';
			$candidates = array_merge( $candidates, glob( $base . $word ) ?: array() );
		}

		foreach ( $candidates as $abs ) {
			$reason = lock_reason( normalize( $abs, $cwd ) );
			if ( null !== $reason ) {
				deny( $cmd, $reason );
			}
		}
	}
}

// Redirect targets are writes no matter which command produced them.
// Lookbehind skips <<, &>, ->, => and 2>&1.
preg_match_all( '#(?<![<&\-=>])>>?\|?\s*([^\s;&|<>()]+)#', $cmd, $m );
foreach ( $m[1] as $target ) {
	if ( '&' === $target[0] || 0 === strpos( trim( $target, '\'"' ), '/dev/' ) ) {
		continue;
	}
	check_words( array( $target ), $cmd );
}

$interpreter_write = '#\b(file_put_contents|fwrite|fopen|unlink|rename|copy|rmdir|writeFile|appendFile|rmSync|unlinkSync|write_text|write_bytes|shutil|os\.remove|os\.rename|os\.replace|os\.unlink)\b|open\([^)]*[\'"][wax+]#';

foreach ( preg_split( '#\s*(?:&&|\|\||[;&|\n])\s*#', $cmd ) as $segment ) {
	$words = preg_split( '#[\s()<>`=,\'"]+#', trim( $segment ), -1, PREG_SPLIT_NO_EMPTY );

	// Skip env assignments and wrappers to find the command itself.
	while ( $words && ( preg_match( '#^\w+=#', $words[0] ) || in_array( $words[0], array( 'sudo', 'command', 'env', 'nohup', 'time', 'xargs', 'exec' ), true ) ) ) {
		array_shift( $words );
	}
	if ( ! $words ) {
		continue;
	}

	$bin  = basename( $words[0] );
	$rest = array_slice( $words, 1 );
	$args = array_values( array_filter( $rest, function ( $w ) { return '' !== $w && '-' !== $w[0]; } ) );

	if ( in_array( $bin, array( 'cp', 'install', 'rsync', 'ln' ), true ) ) {
		check_words( array_slice( $args, -1 ), $cmd );   // Only the destination is written.
	} elseif ( in_array( $bin, array( 'mv', 'rm', 'rmdir', 'truncate', 'touch', 'chmod', 'chown', 'unlink', 'shred', 'patch', 'tee', 'dd' ), true ) ) {
		check_words( array_map( function ( $w ) { return preg_replace( '#^of=#', '', $w ); }, $args ), $cmd );
	} elseif ( in_array( $bin, array( 'sed', 'perl', 'ruby' ), true ) && preg_grep( '#^-[a-zA-Z]*i#', $rest ) ) {
		check_words( $args, $cmd );
	} elseif ( 'git' === $bin && $args && in_array( $args[0], array( 'checkout', 'restore', 'rm', 'mv', 'apply', 'stash', 'reset', 'clean', 'am', 'cherry-pick', 'revert', 'switch', 'update-index', 'update-ref', 'tag' ), true ) ) {
		check_words( array_slice( $args, 1 ), $cmd );
	} elseif ( 'find' === $bin && preg_match( '#\s-(delete|exec|execdir|ok)\b#', $segment ) ) {
		check_words( $args, $cmd );
	} elseif ( preg_match( '#^(php|python[0-9.]*|node|ruby|perl|osascript|bash|sh|zsh)$#', $bin ) && preg_match( $interpreter_write, $segment ) ) {
		check_words( preg_split( '#[\s()<>`=,\'";]+#', $segment, -1, PREG_SPLIT_NO_EMPTY ), $cmd );
	}
}

exit( 0 );
